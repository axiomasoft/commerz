<?php

declare(strict_types=1);

namespace Commerz\Catalog\Projection\Internal;

use Commerz\Catalog\Attributes\Enums\AttributeStatus;
use Commerz\Catalog\Collections\Enums\CollectionStatus;
use Commerz\Catalog\Common\Internal\Documents;
use Commerz\Catalog\Listings\Enums\ListingStatus;
use Commerz\Catalog\Products\Data\ProductId;
use Commerz\Catalog\Products\Enums\ProductStatus;
use Commerz\Catalog\ProductTypes\Enums\AttributeLevel;
use Commerz\Catalog\Projection\Contracts\StorefrontProjection;
use Commerz\Catalog\Projection\Data\ListStorefrontProducts;
use Commerz\Catalog\Projection\Data\StorefrontProductView;
use Commerz\Catalog\Projection\Data\StorefrontVariantView;
use Commerz\Catalog\Projection\Exceptions\StorefrontProductNotFound;
use Commerz\Catalog\Variants\Data\VariantId;
use Commerz\Catalog\Variants\Enums\VariantStatus;
use Commerz\Configuration\CommerzOptions;
use Commerz\Context\CommerceContext;
use Commerz\Errors\InvalidCommand;
use Commerz\Identity\SellableRef;
use Commerz\Operations\Contracts\Transactions;
use Commerz\Pagination\Cursor;
use Commerz\Pagination\Page;
use Commerz\Ports\Prices\Contracts\PriceResolver;
use Commerz\Ports\Prices\Data\ResolvedPrice;
use Commerz\Ports\Prices\Data\ResolvePrice;
use Commerz\Ports\Prices\Exceptions\PriceNotFound;
use Commerz\Ports\Stock\Contracts\StockAvailability;
use Commerz\Ports\Stock\Data\AvailabilityLine;
use Commerz\Ports\Stock\Data\CheckAvailability;
use Commerz\Schema\Rows;
use Commerz\Schema\TableNames;
use Commerz\Stores\Contracts\Channels;
use Commerz\Stores\Data\ChannelId;
use Commerz\Stores\Data\ChannelView;
use Commerz\Stores\Enums\ChannelStatus;
use Commerz\Stores\Exceptions\ChannelNotFound;
use Commerz\Time\Contracts\Clock;
use Commerz\Time\PgTimestamp;
use DateTimeImmutable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Packman\Attributes\Internal;

#[Internal]
final readonly class DatabaseStorefrontProjection implements StorefrontProjection
{
    private const string UUID = '/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/';

    public function __construct(
        private ConnectionResolverInterface $connections,
        private CommerzOptions $config,
        private TableNames $tables,
        private Transactions $transactions,
        private Channels $channels,
        private Clock $clock,
        private Container $container,
    ) {}

    public function find(ChannelId $channel, string $locale, ProductId $product): ?StorefrontProductView
    {
        $row = $this->db()->table($this->tables->table('storefront_products'))
            ->where('channel_id', $channel->value)
            ->where('locale', $locale)
            ->where('product_id', $product->value)
            ->first();

        return $row === null ? null : self::view($row);
    }

    public function get(ChannelId $channel, string $locale, ProductId $product): StorefrontProductView
    {
        return $this->find($channel, $locale, $product)
            ?? throw new StorefrontProductNotFound("{$channel->value}:{$locale}:{$product->value}");
    }

    public function findByHandle(ChannelId $channel, string $locale, string $handle): ?StorefrontProductView
    {
        $db = $this->db();
        $builder = $db->table($this->tables->table('storefront_products'))
            ->where('channel_id', $channel->value)
            ->where('locale', $locale);

        if ($db instanceof Connection && $db->getDriverName() === 'pgsql') {
            $builder->whereRaw("snapshot ->> 'handle' = ?", [$handle]);
        } else {
            $builder->whereRaw("json_extract(snapshot, '$.handle') = ?", [$handle]);
        }

        $row = $builder->first();

        return $row === null ? null : self::view($row);
    }

    public function list(ListStorefrontProducts $query): Page
    {
        $builder = $this->db()->table($this->tables->table('storefront_products'))
            ->where('channel_id', $query->channel->value)
            ->where('locale', $query->locale)
            ->orderBy('product_id')
            ->limit($query->first + 1);

        if ($query->inStock !== null) {
            $builder->where('in_stock', $query->inStock);
        }

        if ($query->minPriceMinor !== null) {
            $builder->whereNotNull('price_max_minor')
                ->where('price_max_minor', '>=', $query->minPriceMinor);
        }

        if ($query->maxPriceMinor !== null) {
            $builder->whereNotNull('price_min_minor')
                ->where('price_min_minor', '<=', $query->maxPriceMinor);
        }

        if ($query->after instanceof Cursor) {
            $afterId = strtolower($query->after->position());

            if (preg_match(self::UUID, $afterId) !== 1) {
                throw new InvalidCommand('after', 'is not a valid cursor', 'common.invalid_cursor');
            }

            $builder->where('product_id', '>', $afterId);
        }

        $rows = array_values($builder->get()->all());
        $more = count($rows) > $query->first;
        $views = array_map(self::view(...), array_slice($rows, 0, $query->first));

        return new Page(
            $views,
            $more && $views !== [] ? Cursor::after($views[array_key_last($views)]->product->value) : null,
        );
    }

    public function projectProduct(ProductId $product, ?ChannelId $channel = null): int
    {
        $db = $this->db();
        $storefrontTable = $this->tables->table('storefront_products');
        $productRow = $db->table($this->tables->table('products').' as p')
            ->join($this->tables->table('product_types').' as t', 't.id', '=', 'p.product_type_id')
            ->where('p.id', $product->value)
            ->select('p.*', 't.kind as product_kind')
            ->first();

        if ($productRow === null || Rows::string($productRow, 'status') !== ProductStatus::Active->value) {
            $this->transactions->run(function () use ($db, $storefrontTable, $product, $channel): void {
                $delete = $db->table($storefrontTable)->where('product_id', $product->value);
                if ($channel instanceof ChannelId) {
                    $delete->where('channel_id', $channel->value);
                }
                $delete->delete();
            });

            return 0;
        }

        $listingQuery = $db->table($this->tables->table('listings'))->where('product_id', $product->value);

        if ($channel instanceof ChannelId) {
            $listingQuery->where('channel_id', $channel->value);
        }

        $listingsByChannel = [];

        foreach ($listingQuery->get() as $listingRow) {
            $listingsByChannel[Rows::string($listingRow, 'channel_id')] = $listingRow;
        }

        $targetChannels = $channel instanceof ChannelId
            ? [$channel->value => true]
            : array_fill_keys(array_keys($listingsByChannel), true);

        if (! $channel instanceof ChannelId) {
            foreach ($db->table($storefrontTable)->where('product_id', $product->value)->distinct()->pluck('channel_id')->all() as $existingChannel) {
                if (is_string($existingChannel)) {
                    $targetChannels[$existingChannel] = true;
                }
            }
        }

        $now = PgTimestamp::current($this->clock);
        $written = 0;

        foreach (array_keys($targetChannels) as $channelIdStr) {
            $channelId = new ChannelId($channelIdStr);
            $listing = $listingsByChannel[$channelIdStr] ?? null;

            if ($listing === null || ! self::isListingLive($listing, $now)) {
                $this->transactions->run(static fn () => $db->table($storefrontTable)
                    ->where('channel_id', $channelIdStr)
                    ->where('product_id', $product->value)
                    ->delete());

                continue;
            }

            try {
                $channelView = $this->channels->find($channelId);
            } catch (ChannelNotFound) {
                $channelView = null;
            }

            if (! $channelView instanceof ChannelView || $channelView->status !== ChannelStatus::Active) {
                $this->transactions->run(static fn () => $db->table($storefrontTable)
                    ->where('channel_id', $channelIdStr)
                    ->where('product_id', $product->value)
                    ->delete());

                continue;
            }

            $written += $this->materializeProductInChannel($db, $productRow, $channelView, $now);
        }

        return $written;
    }

    public function projectChannel(ChannelId $channel): int
    {
        $db = $this->db();
        $storefrontTable = $this->tables->table('storefront_products');

        try {
            $channelView = $this->channels->find($channel);
        } catch (ChannelNotFound) {
            $channelView = null;
        }

        if (! $channelView instanceof ChannelView || $channelView->status !== ChannelStatus::Active) {
            $this->transactions->run(static fn () => $db->table($storefrontTable)->where('channel_id', $channel->value)->delete());

            return 0;
        }

        $now = PgTimestamp::current($this->clock);
        $moment = PgTimestamp::format($now);

        $liveProductIds = array_values(array_filter(
            $db->table($this->tables->table('listings').' as l')
                ->join($this->tables->table('products').' as p', 'p.id', '=', 'l.product_id')
                ->where('l.channel_id', $channel->value)
                ->where('l.status', ListingStatus::Published->value)
                ->where('p.status', ProductStatus::Active->value)
                ->where(static fn (Builder $q) => $q->whereNull('l.available_from')->orWhere('l.available_from', '<=', $moment))
                ->where(static fn (Builder $q) => $q->whereNull('l.available_until')->orWhere('l.available_until', '>', $moment))
                ->orderBy('p.id')
                ->pluck('p.id')
                ->all(),
            is_string(...),
        ));

        $this->transactions->run(function () use ($db, $storefrontTable, $channel, $liveProductIds): void {
            $stale = $db->table($storefrontTable)->where('channel_id', $channel->value);

            if ($liveProductIds !== []) {
                $stale->whereNotIn('product_id', $liveProductIds);
            }

            $stale->delete();
        });

        $written = 0;

        foreach ($liveProductIds as $productId) {
            $written += $this->projectProduct(new ProductId($productId), $channel);
        }

        return $written;
    }

    private function materializeProductInChannel(
        ConnectionInterface $db,
        object $productRow,
        ChannelView $channel,
        DateTimeImmutable $now,
    ): int {
        $productId = Rows::string($productRow, 'id');
        $typeId = Rows::string($productRow, 'product_type_id');
        $handle = Rows::string($productRow, 'handle');
        $kind = Rows::string($productRow, 'product_kind');
        $localizedName = Rows::localized($productRow, 'name');
        $localizedDesc = Rows::nullableString($productRow, 'description') === null ? null : Rows::localized($productRow, 'description');
        $productDoc = Documents::decode(Rows::string($productRow, 'attributes'));

        $variantRows = array_values($db->table($this->tables->table('variants'))
            ->where('product_id', $productId)
            ->where('status', VariantStatus::Active->value)
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->all());

        $slotLevels = [];

        foreach ($db->table($this->tables->table('product_type_attributes').' as s')
            ->join($this->tables->table('attributes').' as a', 'a.id', '=', 's.attribute_id')
            ->where('s.product_type_id', $typeId)
            ->select('a.code', 's.level')
            ->get() as $slotRow) {
            $slotLevels[Rows::string($slotRow, 'code')] = AttributeLevel::from(Rows::string($slotRow, 'level'));
        }

        $overridableCodes = array_keys(array_filter(
            $slotLevels,
            static fn (AttributeLevel $level): bool => $level === AttributeLevel::OverridableVariant,
        ));

        $variantDocs = [];
        $allAttrCodes = array_map(strval(...), array_keys($productDoc));

        foreach ($variantRows as $variantRow) {
            $doc = Documents::decode(Rows::string($variantRow, 'attributes'));
            $variantDocs[Rows::string($variantRow, 'id')] = $doc;
            array_push($allAttrCodes, ...array_map(strval(...), array_keys($doc)));
        }

        $retired = $this->retiredCodes($db, $allAttrCodes);
        $productOnlyAttrs = [];

        foreach ($productDoc as $code => $value) {
            $codeStr = (string) $code;
            if (! isset($retired[$codeStr]) && ($slotLevels[$codeStr] ?? AttributeLevel::Product) === AttributeLevel::Product) {
                $productOnlyAttrs[$codeStr] = $value;
            }
        }
        ksort($productOnlyAttrs, SORT_STRING);

        $categories = $this->categoryCodes($db, $productId);
        $collections = $this->collectionCodes($db, $productId);
        $variantIds = array_map(static fn (object $r): string => Rows::string($r, 'id'), $variantRows);
        $inStockByVariant = $this->resolveStock($channel->id, $variantIds);
        $locales = $channel->locales !== [] ? array_values(array_unique($channel->locales)) : [$channel->defaultLocale];
        $updatedAt = PgTimestamp::format($now);
        $rowsToUpsert = [];

        foreach ($locales as $locale) {
            $pricesByVariant = $this->resolvePrices($channel, $locale, $now, $variantIds);
            $optionsByVariant = $this->resolveOptions($db, $variantIds, $locale);
            $variantSnapshots = [];
            $minPrice = null;
            $maxPrice = null;
            $anyInStock = false;

            foreach ($variantRows as $variantRow) {
                $vId = Rows::string($variantRow, 'id');
                $effective = $variantDocs[$vId];

                foreach ($overridableCodes as $code) {
                    if (! array_key_exists($code, $effective) && array_key_exists($code, $productDoc)) {
                        $effective[$code] = $productDoc[$code];
                    }
                }

                $effective = array_diff_key($effective, $retired);
                ksort($effective, SORT_STRING);

                $price = $pricesByVariant[$vId] ?? null;
                $vInStock = $inStockByVariant[$vId] ?? false;
                $anyInStock = $anyInStock || $vInStock;

                if ($price instanceof ResolvedPrice) {
                    $minor = $price->unit->amountMinor;
                    $minPrice = $minPrice === null ? $minor : min($minPrice, $minor);
                    $maxPrice = $maxPrice === null ? $minor : max($maxPrice, $minor);
                }

                $variantSnapshots[] = [
                    'id' => $vId,
                    'sku' => Rows::string($variantRow, 'sku'),
                    'options' => $optionsByVariant[$vId] ?? [],
                    'effective_attributes' => $effective,
                    'price_minor' => $price?->unit->amountMinor,
                    'compare_at_minor' => $price?->compareAt?->amountMinor,
                    'currency' => $price?->unit->currency->code,
                    'tax_included' => $price instanceof ResolvedPrice ? $price->taxIncluded : $channel->pricesIncludeTax,
                    'in_stock' => $vInStock,
                    'position' => Rows::int($variantRow, 'position'),
                ];
            }

            $snapshot = [
                'product_id' => $productId,
                'handle' => $handle,
                'name' => $localizedName->resolve($locale),
                'description' => $localizedDesc?->resolve($locale),
                'kind' => $kind,
                'currency' => $channel->defaultCurrency->code,
                'product_attributes' => $productOnlyAttrs,
                'categories' => $categories,
                'collections' => $collections,
                'variants' => $variantSnapshots,
            ];

            $rowsToUpsert[] = [
                'channel_id' => $channel->id->value,
                'locale' => $locale,
                'product_id' => $productId,
                'snapshot' => Documents::encode($snapshot),
                'price_min_minor' => $minPrice,
                'price_max_minor' => $maxPrice,
                'in_stock' => $anyInStock,
                'updated_at' => $updatedAt,
            ];
        }

        $storefrontTable = $this->tables->table('storefront_products');

        $this->transactions->run(function () use ($db, $storefrontTable, $channel, $productId, $locales, $rowsToUpsert): void {
            $db->table($storefrontTable)
                ->where('channel_id', $channel->id->value)
                ->where('product_id', $productId)
                ->whereNotIn('locale', $locales)
                ->delete();

            $db->table($storefrontTable)->upsert(
                $rowsToUpsert,
                ['channel_id', 'locale', 'product_id'],
                ['snapshot', 'price_min_minor', 'price_max_minor', 'in_stock', 'updated_at'],
            );
        });

        return count($rowsToUpsert);
    }

    /**
     * @param  list<string>  $variantIds
     * @return array<string, ResolvedPrice> variant id => resolved price
     */
    private function resolvePrices(ChannelView $channel, string $locale, DateTimeImmutable $now, array $variantIds): array
    {
        if ($variantIds === [] || ! $this->container->bound(PriceResolver::class)) {
            return [];
        }

        $resolver = $this->container->make(PriceResolver::class);
        $context = new CommerceContext(
            channel: $channel->id,
            currency: $channel->defaultCurrency,
            locale: $locale,
            customer: null,
            customerGroups: [],
            at: $now,
        );

        $requests = array_map(
            static fn (string $id): ResolvePrice => new ResolvePrice(SellableRef::of('variant', $id), 1),
            $variantIds,
        );

        try {
            $resolved = $resolver->resolveMany($requests, $context);
            $byVariant = [];

            foreach ($variantIds as $idx => $id) {
                if (isset($resolved[$idx])) {
                    $byVariant[$id] = $resolved[$idx];
                }
            }

            return $byVariant;
        } catch (PriceNotFound) {
            // Partial price coverage: resolve each variant individually so priced variants still project.
            $byVariant = [];

            foreach ($variantIds as $id) {
                try {
                    $single = $resolver->resolveMany([new ResolvePrice(SellableRef::of('variant', $id), 1)], $context);
                    if (isset($single[0])) {
                        $byVariant[$id] = $single[0];
                    }
                } catch (PriceNotFound) {
                    continue;
                }
            }

            return $byVariant;
        }
    }

    /**
     * @param  list<string>  $variantIds
     * @return array<string, bool> variant id => sufficient stock for 1 unit
     */
    private function resolveStock(ChannelId $channel, array $variantIds): array
    {
        if ($variantIds === [] || ! $this->container->bound(StockAvailability::class)) {
            return [];
        }

        $availability = $this->container->make(StockAvailability::class);
        $lines = array_map(
            static fn (string $id): AvailabilityLine => new AvailabilityLine(SellableRef::of('variant', $id), 1),
            $variantIds,
        );

        $answer = $availability->check(new CheckAvailability($channel, $lines));
        $result = [];

        foreach ($variantIds as $id) {
            $line = $answer->line(SellableRef::of('variant', $id));
            $result[$id] = $line !== null && $line->isSufficient();
        }

        return $result;
    }

    /**
     * @param  list<string>  $variantIds
     * @return array<string, array<string, string>>
     */
    private function resolveOptions(ConnectionInterface $db, array $variantIds, string $locale): array
    {
        if ($variantIds === []) {
            return [];
        }

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

        $options = [];

        foreach ($rows as $row) {
            $vId = Rows::string($row, 'variant_id');
            $name = Rows::localized($row, 'option_name')->resolve($locale);
            $code = Rows::string($row, 'option_code');
            $key = isset($options[$vId][$name]) ? "{$name} [{$code}]" : $name;
            $options[$vId][$key] = Rows::localized($row, 'value_label')->resolve($locale);
        }

        return $options;
    }

    /** @return list<string> */
    private function categoryCodes(ConnectionInterface $db, string $productId): array
    {
        $codes = array_values(array_filter(
            $db->table($this->tables->table('category_products').' as cp')
                ->join($this->tables->table('categories').' as c', 'c.id', '=', 'cp.category_id')
                ->where('cp.product_id', $productId)
                ->orderBy('cp.position')
                ->pluck('c.code')
                ->all(),
            is_string(...),
        ));

        return array_values(array_unique($codes));
    }

    /** @return list<string> */
    private function collectionCodes(ConnectionInterface $db, string $productId): array
    {
        $collectionsTable = $this->tables->table('collections');

        if ($db instanceof Connection && ! $db->getSchemaBuilder()->hasTable($collectionsTable)) {
            return [];
        }

        $codes = array_values(array_filter(
            $db->table($this->tables->table('collection_products').' as cp')
                ->join($collectionsTable.' as c', 'c.id', '=', 'cp.collection_id')
                ->where('cp.product_id', $productId)
                ->where('c.status', CollectionStatus::Active->value)
                ->orderBy('c.code')
                ->pluck('c.code')
                ->all(),
            is_string(...),
        ));

        return array_values(array_unique($codes));
    }

    /**
     * @param  list<string>  $codes
     * @return array<string, true>
     */
    private function retiredCodes(ConnectionInterface $db, array $codes): array
    {
        if ($codes === []) {
            return [];
        }

        $retired = $db->table($this->tables->table('attributes'))
            ->whereIn('code', array_values(array_unique($codes)))
            ->where('status', AttributeStatus::Retired->value)
            ->pluck('code')
            ->all();

        return array_fill_keys(array_filter($retired, is_string(...)), true);
    }

    private static function isListingLive(object $listing, DateTimeImmutable $now): bool
    {
        if (Rows::string($listing, 'status') !== ListingStatus::Published->value) {
            return false;
        }

        $from = Rows::nullableString($listing, 'available_from');
        $until = Rows::nullableString($listing, 'available_until');

        if ($from !== null && $now < new DateTimeImmutable($from)) {
            return false;
        }

        if ($until !== null && $now >= new DateTimeImmutable($until)) {
            return false;
        }

        return true;
    }

    private static function view(object $row): StorefrontProductView
    {
        $rawSnapshot = Documents::decode(Rows::string($row, 'snapshot'));
        /** @var array<string, mixed> $snapshot */
        $snapshot = [];
        foreach ($rawSnapshot as $k => $v) {
            $snapshot[(string) $k] = $v;
        }

        $productAttrs = [];
        if (isset($snapshot['product_attributes']) && is_array($snapshot['product_attributes'])) {
            foreach ($snapshot['product_attributes'] as $k => $v) {
                $productAttrs[(string) $k] = $v;
            }
        }

        $categories = [];
        if (isset($snapshot['categories']) && is_array($snapshot['categories'])) {
            foreach ($snapshot['categories'] as $c) {
                if (is_string($c)) {
                    $categories[] = $c;
                }
            }
        }

        $collections = [];
        if (isset($snapshot['collections']) && is_array($snapshot['collections'])) {
            foreach ($snapshot['collections'] as $c) {
                if (is_string($c)) {
                    $collections[] = $c;
                }
            }
        }

        $variants = [];
        if (isset($snapshot['variants']) && is_array($snapshot['variants'])) {
            foreach ($snapshot['variants'] as $v) {
                if (! is_array($v) || ! isset($v['id'], $v['sku']) || ! is_string($v['id']) || ! is_string($v['sku'])) {
                    continue;
                }

                $opts = [];
                if (isset($v['options']) && is_array($v['options'])) {
                    foreach ($v['options'] as $ok => $ov) {
                        if (is_string($ov)) {
                            $opts[(string) $ok] = $ov;
                        }
                    }
                }

                $effAttrs = [];
                if (isset($v['effective_attributes']) && is_array($v['effective_attributes'])) {
                    foreach ($v['effective_attributes'] as $ak => $av) {
                        $effAttrs[(string) $ak] = $av;
                    }
                }

                $variants[] = new StorefrontVariantView(
                    id: new VariantId($v['id']),
                    sku: $v['sku'],
                    options: $opts,
                    effectiveAttributes: $effAttrs,
                    priceMinor: isset($v['price_minor']) && is_int($v['price_minor']) ? $v['price_minor'] : null,
                    compareAtMinor: isset($v['compare_at_minor']) && is_int($v['compare_at_minor']) ? $v['compare_at_minor'] : null,
                    currency: isset($v['currency']) && is_string($v['currency']) ? $v['currency'] : null,
                    taxIncluded: isset($v['tax_included']) && is_bool($v['tax_included']) && $v['tax_included'],
                    inStock: isset($v['in_stock']) && is_bool($v['in_stock']) && $v['in_stock'],
                    position: isset($v['position']) && is_int($v['position']) ? $v['position'] : 0,
                );
            }
        }

        return new StorefrontProductView(
            channel: new ChannelId(Rows::string($row, 'channel_id')),
            locale: Rows::string($row, 'locale'),
            product: new ProductId(Rows::string($row, 'product_id')),
            handle: isset($snapshot['handle']) && is_string($snapshot['handle']) ? $snapshot['handle'] : '',
            name: isset($snapshot['name']) && is_string($snapshot['name']) ? $snapshot['name'] : '',
            description: isset($snapshot['description']) && is_string($snapshot['description']) ? $snapshot['description'] : null,
            kind: isset($snapshot['kind']) && is_string($snapshot['kind']) ? $snapshot['kind'] : 'physical',
            productAttributes: $productAttrs,
            categories: $categories,
            collections: $collections,
            variants: $variants,
            priceMinMinor: Rows::nullableInt($row, 'price_min_minor'),
            priceMaxMinor: Rows::nullableInt($row, 'price_max_minor'),
            currency: isset($snapshot['currency']) && is_string($snapshot['currency']) ? $snapshot['currency'] : null,
            inStock: Rows::bool($row, 'in_stock'),
            snapshot: $snapshot,
            updatedAt: Rows::date($row, 'updated_at'),
        );
    }

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->config->connection);
    }
}
