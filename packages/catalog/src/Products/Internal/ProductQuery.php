<?php

declare(strict_types=1);

namespace Commerz\Catalog\Products\Internal;

use Commerz\Catalog\Categories\Data\CategoryId;
use Commerz\Catalog\Common\Internal\Clock;
use Commerz\Catalog\Listings\Enums\ListingStatus;
use Commerz\Catalog\Products\Data\ListProducts;
use Commerz\Catalog\Products\Enums\ProductStatus;
use Commerz\Catalog\ProductTypes\Data\ProductTypeId;
use Commerz\Catalog\Variants\Enums\VariantStatus;
use Commerz\Configuration\CommerzOptions;
use Commerz\Pagination\Cursor;
use Commerz\Stores\Data\ChannelId;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;
use Packman\Attributes\Internal;
use Packman\Schema\Data\Compiler\TableNames;

/** The single SQL of `Products::list` over `products p` (knowledge/catalog-design#filters, D19). */
#[Internal]
final readonly class ProductQuery
{
    public function __construct(
        private ConnectionResolverInterface $connections,
        private CommerzOptions $config,
        private TableNames $tables,
        private FilterCompiler $filters,
    ) {}

    /** @return list<object> product rows ordered by handle, at most `first + 1` */
    public function rows(ListProducts $query): array
    {
        // Filters are compiled (and refused) before any product is read.
        $conditions = $this->filters->compile($query->filters);

        if ($conditions === null) {
            return [];
        }

        $db = $this->connections->connection($this->config->connection);
        $products = $db->table($this->tables->table('products').' as p')
            ->select('p.*')
            ->orderBy('p.handle')
            ->limit($query->first + 1);

        if ($query->type instanceof ProductTypeId) {
            $products->where('p.product_type_id', $query->type->value);
        }

        if ($query->channel instanceof ChannelId) {
            $products->where('p.status', ProductStatus::Active->value);
            $this->listedIn($products, $query->channel, $query->at);
        } elseif ($query->status instanceof ProductStatus) {
            $products->where('p.status', $query->status->value);
        } else {
            $products->where('p.status', '<>', ProductStatus::Archived->value);
        }

        $subtree = null;

        if ($query->category instanceof CategoryId) {
            $categories = $products->getGrammar()->wrapTable($this->tables->table('categories'));
            $subtree = "with recursive sub as (select id from {$categories} where id = ?"
                ." union all select c.id from {$categories} c join sub on c.parent_id = sub.id) ";
            $assignments = $this->tables->table('category_products');
            $products->whereExists(static fn (Builder $cp) => $cp->selectRaw('1')
                ->from($assignments.' as cp')
                ->whereColumn('cp.product_id', 'p.id')
                ->whereIn('cp.category_id', static fn (Builder $sub) => $sub->select('id')->from('sub')));
        }

        if ($conditions !== []) {
            $variants = $this->tables->table('variants');

            // CAT-03: every filter is checked against the same active variant.
            $products->whereExists(static function (Builder $v) use ($variants, $conditions): void {
                $v->selectRaw('1')
                    ->from($variants.' as v')
                    ->whereColumn('v.product_id', 'p.id')
                    ->where('v.status', VariantStatus::Active->value);

                foreach ($conditions as $condition) {
                    $condition($v);
                }
            });
        }

        if ($query->after instanceof Cursor) {
            $products->where('p.handle', '>', $query->after->position());
        }

        if ($subtree !== null) {
            // The CTE and the builder query form one statement; identifiers come only from TableNames and the grammar.
            return array_values(array_filter($db->select(
                $subtree.$products->toSql(),
                [$query->category->value, ...$products->getBindings()],
            ), is_object(...)));
        }

        return array_values($products->get()->all());
    }

    private function listedIn(Builder $products, ChannelId $channel, ?DateTimeImmutable $at): void
    {
        $listings = $this->tables->table('listings');
        $moment = $at?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP') ?? Clock::now();

        $products->whereExists(static fn (Builder $l) => $l->selectRaw('1')
            ->from($listings.' as l')
            ->whereColumn('l.product_id', 'p.id')
            ->where('l.channel_id', $channel->value)
            ->where('l.status', ListingStatus::Published->value)
            ->where(static fn (Builder $from) => $from->whereNull('l.available_from')->orWhere('l.available_from', '<=', $moment))
            ->where(static fn (Builder $until) => $until->whereNull('l.available_until')->orWhere('l.available_until', '>', $moment)));
    }
}
