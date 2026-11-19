<?php

declare(strict_types=1);

namespace Commerz\Catalog\Variants\Internal;

use Commerz\Catalog\Attributes\Enums\AttributeStatus;
use Commerz\Catalog\Common\Internal\Documents;
use Commerz\Catalog\Common\Internal\Rows;
use Commerz\Catalog\Listings\Enums\ListingStatus;
use Commerz\Catalog\Products\Enums\ProductStatus;
use Commerz\Catalog\ProductTypes\Contracts\ProductKind;
use Commerz\Catalog\Variants\Enums\VariantStatus;
use Commerz\Configuration\CommerzOptions;
use Commerz\Context\CommerceContext;
use Commerz\Ports\Sellables\Contracts\SellableProvider;
use Commerz\Ports\Sellables\Data\SellableSnapshot;
use Commerz\Ports\Sellables\Enums\SellableKind;
use Commerz\Schema\TableNames;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\JoinClause;
use Packman\Attributes\Internal;
use Packman\Plugins\Contracts\Contributions\Contributions;

/**
 * The `variant` sellable provider (knowledge/catalog-design#sellables): five queries however large the batch,
 * no writes, no transaction. The first failing purchasability reason wins, in the documented order.
 */
#[Internal]
final readonly class VariantSellables implements SellableProvider
{
    private const string UUID = '/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/';

    public function __construct(
        private ConnectionResolverInterface $connections,
        private CommerzOptions $config,
        private TableNames $tables,
        private Container $container,
    ) {}

    public function key(): string
    {
        return 'variant';
    }

    public function resolve(array $refs, CommerceContext $context): array
    {
        $byId = [];

        foreach ($refs as $ref) {
            $id = strtolower($ref->id);

            if (preg_match(self::UUID, $id) === 1) {
                $byId[$id][] = $ref;
            }
        }

        if ($byId === []) {
            return [];
        }

        $db = $this->connections->connection($this->config->connection);
        $rows = $this->variants($db, array_keys($byId));

        if ($rows === []) {
            return [];
        }

        $productIds = array_values(array_unique(array_map(static fn (object $row): string => Rows::string($row, 'product_id'), $rows)));
        $variantIds = array_map(static fn (object $row): string => Rows::string($row, 'id'), $rows);

        $listings = $this->listings($db, $productIds, $context);
        $options = $this->options($db, $variantIds, $context->locale);
        $categories = $this->categories($db, $productIds);

        // Resolved here, not injected: the registry instantiates its providers while it is being compiled.
        $contributions = $this->container->make(Contributions::class);
        $documents = [];

        foreach ($rows as $row) {
            $documents[Rows::string($row, 'id')] = $this->document($row);
        }

        $retired = $this->retired($db, $documents);
        $found = [];

        foreach ($rows as $row) {
            $id = Rows::string($row, 'id');
            $productId = Rows::string($row, 'product_id');
            $kind = $this->kind($contributions, Rows::string($row, 'kind'));
            $reason = $this->reason($row, $listings[$productId] ?? null, $kind !== null, $context);

            foreach ($byId[$id] as $ref) {
                $found[(string) $ref] = new SellableSnapshot(
                    ref: $ref,
                    sku: Rows::string($row, 'sku'),
                    name: Rows::localized($row, 'product_name')->resolve($context->locale),
                    kind: $kind?->sellableKind() ?? SellableKind::Physical,
                    requiresShipping: $kind?->requiresShipping() ?? true,
                    purchasable: $reason === null,
                    unavailableReason: $reason,
                    options: $options[$id] ?? [],
                    taxClass: Rows::nullableString($row, 'tax_class'),
                    weightGrams: Rows::nullableInt($row, 'weight_grams'),
                    attributes: self::facts($documents[$id], $retired),
                    categories: $categories[$productId] ?? [],
                );
            }
        }

        return $found;
    }

    /**
     * @param  list<string>  $ids
     * @return list<object>
     */
    private function variants(ConnectionInterface $db, array $ids): array
    {
        return array_values($db->table($this->tables->table('variants').' as v')
            ->join($this->tables->table('products').' as p', 'p.id', '=', 'v.product_id')
            ->join($this->tables->table('product_types').' as t', 't.id', '=', 'p.product_type_id')
            ->whereIn('v.id', $ids)
            ->select([
                'v.id', 'v.product_id', 'v.sku', 'v.status', 'v.tax_class', 'v.weight_grams', 'v.attributes as variant_attributes',
                'p.name as product_name', 'p.status as product_status', 'p.attributes as product_attributes', 't.kind',
            ])
            ->get()->all());
    }

    /**
     * @param  list<string>  $productIds
     * @return array<string, object> product id => listing row of the context's channel
     */
    private function listings(ConnectionInterface $db, array $productIds, CommerceContext $context): array
    {
        $listings = [];

        $rows = $db->table($this->tables->table('listings'))
            ->where('channel_id', $context->channel->value)
            ->whereIn('product_id', $productIds)
            ->get();

        foreach ($rows as $row) {
            $listings[Rows::string($row, 'product_id')] = $row;
        }

        return $listings;
    }

    /**
     * @param  list<string>  $variantIds
     * @return array<string, array<string, string>> variant id => option name => value label, by axis position
     */
    private function options(ConnectionInterface $db, array $variantIds, string $locale): array
    {
        $rows = $db->table($this->tables->table('variant_option_values').' as vov')
            ->join($this->tables->table('options').' as o', 'o.id', '=', 'vov.option_id')
            ->join($this->tables->table('option_values').' as ov', 'ov.id', '=', 'vov.option_value_id')
            ->join($this->tables->table('product_options').' as po', static function (JoinClause $join): void {
                $join->on('po.product_id', '=', 'vov.product_id')->on('po.option_id', '=', 'vov.option_id');
            })
            ->whereIn('vov.variant_id', $variantIds)
            ->orderBy('po.position')
            ->orderBy('o.code')
            ->select(['vov.variant_id', 'o.name as option_name', 'o.code as option_code', 'ov.label as value_label'])
            ->get();

        $axes = [];

        foreach ($rows as $row) {
            $axes[Rows::string($row, 'variant_id')][] = [
                'name' => Rows::localized($row, 'option_name')->resolve($locale),
                'code' => Rows::string($row, 'option_code'),
                'value' => Rows::localized($row, 'value_label')->resolve($locale),
            ];
        }

        $options = [];

        foreach ($axes as $variant => $variantAxes) {
            $names = array_count_values(array_column($variantAxes, 'name'));
            $values = [];

            foreach ($variantAxes as $axis) {
                $name = $axis['name'];
                $key = $names[$name] > 1 ? $name.' ['.$axis['code'].']' : $name;

                // Also avoid a generated key colliding with another axis's literal display name.
                while (array_key_exists($key, $values) || ($key !== $name && array_key_exists($key, $names))) {
                    $key .= ' ['.$axis['code'].']';
                }

                $values[$key] = $axis['value'];
            }

            $options[$variant] = $values;
        }

        return $options;
    }

    /**
     * Codes of the assigned categories and of all their ancestors.
     *
     * @param  list<string>  $productIds
     * @return array<string, list<string>> product id => sorted unique category codes
     */
    private function categories(ConnectionInterface $db, array $productIds): array
    {
        $categories = $db->table($this->tables->table('categories'))->getGrammar()->wrapTable($this->tables->table('categories'));
        $assignments = $db->table($this->tables->table('category_products'))->getGrammar()->wrapTable($this->tables->table('category_products'));
        $placeholders = implode(', ', array_fill(0, count($productIds), '?'));

        $rows = $db->select(
            'with recursive tree (product_id, id, parent_id, code) as ('
            ."select cp.product_id, c.id, c.parent_id, c.code from {$assignments} cp join {$categories} c on c.id = cp.category_id where cp.product_id in ({$placeholders})"
            ." union select tree.product_id, c.id, c.parent_id, c.code from tree join {$categories} c on c.id = tree.parent_id"
            .') select distinct product_id, code from tree',
            $productIds,
        );

        $codes = [];

        foreach ($rows as $row) {
            if (is_object($row)) {
                $codes[Rows::string($row, 'product_id')][] = Rows::string($row, 'code');
            }
        }

        return array_map(static function (array $list): array {
            sort($list);

            return $list;
        }, $codes);
    }

    /**
     * @param  array<string, array<array-key, mixed>>  $documents
     * @return list<string> codes of the retired attributes among those the documents hold
     */
    private function retired(ConnectionInterface $db, array $documents): array
    {
        $codes = [];

        foreach ($documents as $document) {
            foreach (array_keys($document) as $code) {
                $codes[(string) $code] = true;
            }
        }

        if ($codes === []) {
            return [];
        }

        $rows = $db->table($this->tables->table('attributes'))
            ->whereIn('code', array_keys($codes))
            ->where('status', AttributeStatus::Retired->value)
            ->select('code')
            ->get();

        return array_values(array_map(static fn (object $row): string => Rows::string($row, 'code'), $rows->all()));
    }

    /** @return array<array-key, mixed> product and variant attribute documents merged; their keys never overlap */
    private function document(object $row): array
    {
        return array_replace(
            Documents::decode(Rows::string($row, 'product_attributes')),
            Documents::decode(Rows::string($row, 'variant_attributes')),
        );
    }

    /**
     * Scalar facts for promotion conditions: arrays (localized text, multi enum, measure) and retired attributes stay out.
     *
     * @param  array<array-key, mixed>  $document
     * @param  list<string>  $retired
     * @return array<string, string|int|bool>
     */
    private static function facts(array $document, array $retired): array
    {
        $facts = [];

        foreach ($document as $code => $value) {
            if ((is_string($value) || is_int($value) || is_bool($value)) && ! in_array((string) $code, $retired, true)) {
                $facts[(string) $code] = $value;
            }
        }

        ksort($facts);

        return $facts;
    }

    private function kind(Contributions $contributions, string $key): ?ProductKind
    {
        return $contributions->has(ProductKind::KIND, $key) ? $contributions->get(ProductKind::KIND, $key, ProductKind::class) : null;
    }

    private function reason(object $row, ?object $listing, bool $kindSupported, CommerceContext $context): ?string
    {
        if (Rows::string($row, 'product_status') !== ProductStatus::Active->value) {
            return 'product_inactive';
        }

        if (Rows::string($row, 'status') !== VariantStatus::Active->value) {
            return 'variant_archived';
        }

        if ($listing === null || Rows::string($listing, 'status') !== ListingStatus::Published->value) {
            return 'not_listed';
        }

        $at = $context->at->setTimezone(new DateTimeZone('UTC'));
        $from = Rows::nullableString($listing, 'available_from');
        $until = Rows::nullableString($listing, 'available_until');

        if ($from !== null && $at < new DateTimeImmutable($from)) {
            return 'not_available_yet';
        }

        if ($until !== null && $at >= new DateTimeImmutable($until)) {
            return 'no_longer_available';
        }

        return $kindSupported ? null : 'unsupported_kind';
    }
}
