<?php

declare(strict_types=1);

namespace Commerz\Catalog\Search\Internal;

use Brick\Math\BigDecimal;
use Closure;
use Commerz\Catalog\Attributes\Contracts\AttributeType;
use Commerz\Catalog\Attributes\Data\IndexFact;
use Commerz\Catalog\Attributes\Enums\AttributeStatus;
use Commerz\Catalog\Attributes\Enums\FilterOperator;
use Commerz\Catalog\Attributes\Exceptions\AttributeNotFilterable;
use Commerz\Catalog\Attributes\Exceptions\AttributeNotFound;
use Commerz\Catalog\Attributes\Exceptions\InvalidAttributeValue;
use Commerz\Catalog\Attributes\Internal\StoredAttribute;
use Commerz\Catalog\Categories\Data\CategoryId;
use Commerz\Catalog\Common\Internal\BatchAttributeProcessor;
use Commerz\Catalog\Common\Internal\Documents;
use Commerz\Catalog\Listings\Enums\ListingStatus;
use Commerz\Catalog\Products\Data\AttributeFilter;
use Commerz\Catalog\Products\Data\ProductId;
use Commerz\Catalog\Products\Data\ProductView;
use Commerz\Catalog\Products\Data\VariantFilter;
use Commerz\Catalog\Products\Enums\ProductStatus;
use Commerz\Catalog\ProductTypes\Data\ProductTypeId;
use Commerz\Catalog\Search\Contracts\CatalogSearchPort;
use Commerz\Catalog\Search\Data\CatalogSearchQuery;
use Commerz\Catalog\Search\Data\CatalogSearchResult;
use Commerz\Catalog\Search\Data\FacetDistribution;
use Commerz\Catalog\Search\Data\FacetRequest;
use Commerz\Catalog\Search\Data\FacetValueCount;
use Commerz\Catalog\Search\Enums\FacetKind;
use Commerz\Catalog\Search\Enums\SearchSort;
use Commerz\Catalog\Variants\Enums\VariantStatus;
use Commerz\Configuration\CommerzOptions;
use Commerz\Errors\InvalidCommand;
use Commerz\Pagination\Page;
use Commerz\Schema\Rows;
use Commerz\Schema\TableNames;
use Commerz\Stores\Data\ChannelId;
use Commerz\Time\Contracts\Clock;
use Commerz\Time\PgTimestamp;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;
use Override;
use Packman\Attributes\Internal;
use Packman\Plugins\Contracts\Contributions\Contributions;

/**
 * Built-in PostgreSQL implementation of `CatalogSearchPort` over `cmz_attribute_index` and `cmz_variant_option_values`.
 *
 * Computes exact totals, total-order keyset cursors (zero duplicates/gaps), and disjunctive facet distributions while
 * enforcing CAT-03 (every filter holds on one and the same active variant).
 */
#[Internal]
final readonly class PgCatalogSearch implements CatalogSearchPort
{
    /** Pure literal SQL expressions for exact integer relevance scoring (zero float drift, zero dynamic SQL). */
    private const string PG_RELEVANCE_SQL = '(case when lower(p.handle) = lower(?) then 100 else 0 end)'
        ." + (case when p.handle ilike ? escape '\\' then 60 else 0 end)"
        ." + (case when p.handle ilike ? escape '\\' then 40 else 0 end)"
        ." + (case when p.name::text ilike ? escape '\\' then 30 else 0 end)"
        ." + (case when coalesce(p.description::text, '') ilike ? escape '\\' then 10 else 0 end)";

    private const string SQLITE_RELEVANCE_SQL = '(case when lower(p.handle) = lower(?) then 100 else 0 end)'
        ." + (case when lower(p.handle) like lower(?) escape '\\' then 60 else 0 end)"
        ." + (case when lower(p.handle) like lower(?) escape '\\' then 40 else 0 end)"
        ." + (case when lower(p.name) like lower(?) escape '\\' then 30 else 0 end)"
        ." + (case when lower(coalesce(p.description, '')) like lower(?) escape '\\' then 10 else 0 end)";

    /** Projection column of a fact => PostgreSQL cast type. */
    private const array PG_CASTS = [
        'choice_id' => 'uuid',
        'num' => 'numeric',
        'flag' => 'boolean',
        'day' => 'date',
        'term' => 'varchar',
    ];

    public function __construct(
        private ConnectionResolverInterface $connections,
        private CommerzOptions $config,
        private TableNames $tables,
        private Contributions $contributions,
        private BatchAttributeProcessor $attributes,
        private Clock $clock,
    ) {}

    #[Override]
    public function search(CatalogSearchQuery $query): CatalogSearchResult
    {
        $db = $this->db();
        $isPg = $this->isPostgres($db);

        $cursor = $query->after !== null ? SearchCursor::decode($query->after, $query->sort) : null;

        // Preload all attribute definitions referenced by filters or facets in one pass.
        $attributeCodes = $this->collectAttributeCodes($query);
        $attributeDefinitions = $this->attributes->readDefinitions($db, $attributeCodes);

        // Compile all main filters upfront so invalid filters fail fast before executing queries.
        $mainConditions = $this->compileFilters($db, $query->filters, $attributeDefinitions, $isPg);

        $facets = $this->computeFacets($db, $query, $attributeDefinitions, $isPg);

        if ($mainConditions === null) {
            return new CatalogSearchResult(new Page([], null), 0, $facets);
        }

        $total = $this->countMatches($db, $query, $mainConditions, $isPg);

        if ($total === 0) {
            return new CatalogSearchResult(new Page([], null), 0, $facets);
        }

        $rows = $this->fetchPageRows($db, $query, $mainConditions, $cursor, $isPg);
        $hasMore = count($rows) > $query->first;
        $pageRows = array_slice($rows, 0, $query->first);
        $views = $this->hydrateViews($db, $pageRows);

        $nextCursor = null;

        if ($hasMore && $pageRows !== []) {
            $lastRow = $pageRows[array_key_last($pageRows)];
            $nextCursor = (new SearchCursor(
                sort: $query->sort,
                id: Rows::string($lastRow, 'id'),
                handle: Rows::string($lastRow, 'handle'),
                createdAt: ($query->sort === SearchSort::Newest || $query->sort === SearchSort::Oldest)
                    ? Rows::string($lastRow, 'created_at')
                    : null,
                relevance: $query->sort === SearchSort::Relevance
                    ? Rows::int($lastRow, 'search_relevance')
                    : null,
            ))->toCursor();
        }

        return new CatalogSearchResult(new Page($views, $nextCursor), $total, $facets);
    }

    /**
     * Built-in PG adapter queries `cmz_products` and `cmz_attribute_index` directly; indexing is a live no-op.
     *
     * @param  list<ProductId>  $productIds
     */
    #[Override]
    public function indexProducts(array $productIds): void {}

    /**
     * Built-in PG adapter queries live catalog tables directly; removal is a no-op.
     *
     * @param  list<ProductId>  $productIds
     */
    #[Override]
    public function removeProducts(array $productIds): void {}

    /**
     * @param  list<Closure(Builder): void>  $conditions
     */
    private function countMatches(ConnectionInterface $db, CatalogSearchQuery $query, array $conditions, bool $isPg): int
    {
        $builder = $this->baseProductBuilder($db, $query, $conditions, $isPg);
        $builder->selectRaw('count(*) as aggregate');

        $rows = $this->executeWithCategoryCte($db, $builder, $query->category);
        $first = $rows[0] ?? null;

        return is_object($first) ? Rows::int($first, 'aggregate') : 0;
    }

    /**
     * @param  list<Closure(Builder): void>  $conditions
     * @return list<object>
     */
    private function fetchPageRows(
        ConnectionInterface $db,
        CatalogSearchQuery $query,
        array $conditions,
        ?SearchCursor $cursor,
        bool $isPg,
    ): array {
        $builder = $this->baseProductBuilder($db, $query, $conditions, $isPg);

        if ($query->sort === SearchSort::Relevance) {
            $builder->select('p.*');

            if ($query->term === null) {
                $builder->selectRaw('0 as search_relevance');

                if ($cursor instanceof SearchCursor) {
                    if ($isPg) {
                        $builder->whereRaw('(p.handle > ? or (p.handle = ? and p.id > ?::uuid))', [$cursor->handle, $cursor->handle, $cursor->id]);
                    } else {
                        $builder->whereRaw('(p.handle > ? or (p.handle = ? and p.id > ?))', [$cursor->handle, $cursor->handle, $cursor->id]);
                    }
                }
            } else {
                $escaped = self::escapeLike($query->term);
                $scoreBindings = [$query->term, $escaped.'%', '%'.$escaped.'%', '%'.$escaped.'%', '%'.$escaped.'%'];

                if ($isPg) {
                    $builder->selectRaw('('.self::PG_RELEVANCE_SQL.') as search_relevance', $scoreBindings);
                } else {
                    $builder->selectRaw('('.self::SQLITE_RELEVANCE_SQL.') as search_relevance', $scoreBindings);
                }

                if ($cursor instanceof SearchCursor) {
                    $relevance = $cursor->relevance ?? 0;
                    $cursorBindings = [...$scoreBindings, $relevance, ...$scoreBindings, $relevance, $cursor->handle, $cursor->handle, $cursor->id];

                    if ($isPg) {
                        $builder->whereRaw(
                            '(('.self::PG_RELEVANCE_SQL.') < ? or (('.self::PG_RELEVANCE_SQL.') = ? and (p.handle > ? or (p.handle = ? and p.id > ?::uuid))))',
                            $cursorBindings,
                        );
                    } else {
                        $builder->whereRaw(
                            '(('.self::SQLITE_RELEVANCE_SQL.') < ? or (('.self::SQLITE_RELEVANCE_SQL.') = ? and (p.handle > ? or (p.handle = ? and p.id > ?))))',
                            $cursorBindings,
                        );
                    }
                }
            }

            $builder->orderByDesc('search_relevance')
                ->orderBy('p.handle')
                ->orderBy('p.id');
        } else {
            $builder->select('p.*');
            $this->applyOrderingAndCursor($builder, $query->sort, $cursor, $isPg);
        }

        $builder->limit($query->first + 1);

        return $this->executeWithCategoryCte($db, $builder, $query->category);
    }

    private function applyOrderingAndCursor(Builder $builder, SearchSort $sort, ?SearchCursor $cursor, bool $isPg): void
    {
        $idCast = $isPg ? '?::uuid' : '?';
        $tsCast = $isPg ? '?::timestamptz' : '?';

        match ($sort) {
            SearchSort::HandleAsc => $builder->orderBy('p.handle')->orderBy('p.id'),
            SearchSort::HandleDesc => $builder->orderByDesc('p.handle')->orderByDesc('p.id'),
            SearchSort::Newest => $builder->orderByDesc('p.created_at')->orderByDesc('p.id'),
            SearchSort::Oldest => $builder->orderBy('p.created_at')->orderBy('p.id'),
            SearchSort::Relevance => $builder->orderBy('p.handle')->orderBy('p.id'),
        };

        if (! $cursor instanceof SearchCursor) {
            return;
        }

        match ($sort) {
            SearchSort::HandleAsc => $builder->whereRaw(
                "(p.handle > ? or (p.handle = ? and p.id > {$idCast}))",
                [$cursor->handle, $cursor->handle, $cursor->id],
            ),
            SearchSort::HandleDesc => $builder->whereRaw(
                "(p.handle < ? or (p.handle = ? and p.id < {$idCast}))",
                [$cursor->handle, $cursor->handle, $cursor->id],
            ),
            SearchSort::Newest => $builder->whereRaw(
                "(p.created_at < {$tsCast} or (p.created_at = {$tsCast} and p.id < {$idCast}))",
                [(string) $cursor->createdAt, (string) $cursor->createdAt, $cursor->id],
            ),
            SearchSort::Oldest => $builder->whereRaw(
                "(p.created_at > {$tsCast} or (p.created_at = {$tsCast} and p.id > {$idCast}))",
                [(string) $cursor->createdAt, (string) $cursor->createdAt, $cursor->id],
            ),
            SearchSort::Relevance => null,
        };
    }

    /**
     * @param  list<Closure(Builder): void>  $conditions
     */
    private function baseProductBuilder(
        ConnectionInterface $db,
        CatalogSearchQuery $query,
        array $conditions,
        bool $isPg,
        bool $includeVariantConditions = true,
    ): Builder {
        $products = $db->table($this->tables->table('products').' as p');

        if ($query->type instanceof ProductTypeId) {
            $products->where('p.product_type_id', $query->type->value);
        }

        if ($query->channel instanceof ChannelId) {
            $products->where('p.status', ProductStatus::Active->value);
            $this->applyChannelFilter($products, $query->channel, $query->at);
        } elseif ($query->status instanceof ProductStatus) {
            $products->where('p.status', $query->status->value);
        } else {
            $products->where('p.status', '<>', ProductStatus::Archived->value);
        }

        if ($query->category instanceof CategoryId) {
            $assignments = $this->tables->table('category_products');
            $products->whereExists(static fn (Builder $cp): Builder => $cp->selectRaw('1')
                ->from($assignments.' as cp')
                ->whereColumn('cp.product_id', 'p.id')
                ->whereIn('cp.category_id', static fn (Builder $sub): Builder => $sub->select('id')->from('sub')));
        }

        if ($query->term !== null) {
            $this->applyTermFilter($products, $query->term, $query->locale, $isPg);
        }

        if ($includeVariantConditions && $conditions !== []) {
            $variants = $this->tables->table('variants');
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

        return $products;
    }

    private function applyChannelFilter(Builder $products, ChannelId $channel, ?DateTimeImmutable $at): void
    {
        $listings = $this->tables->table('listings');
        $moment = $at instanceof DateTimeImmutable
            ? PgTimestamp::format($at)
            : PgTimestamp::now($this->clock);

        $products->whereExists(static fn (Builder $l): Builder => $l->selectRaw('1')
            ->from($listings.' as l')
            ->whereColumn('l.product_id', 'p.id')
            ->where('l.channel_id', $channel->value)
            ->where('l.status', ListingStatus::Published->value)
            ->where(static fn (Builder $from): Builder => $from->whereNull('l.available_from')->orWhere('l.available_from', '<=', $moment))
            ->where(static fn (Builder $until): Builder => $until->whereNull('l.available_until')->orWhere('l.available_until', '>', $moment)));
    }

    private function applyTermFilter(Builder $products, string $term, ?string $locale, bool $isPg): void
    {
        $pattern = '%'.self::escapeLike($term).'%';
        $variants = $this->tables->table('variants');
        $index = $this->tables->table('attribute_index');

        $products->where(static function (Builder $q) use ($pattern, $locale, $variants, $index, $isPg): void {
            if ($isPg) {
                $q->whereRaw("p.handle ilike ? escape '\\'", [$pattern])
                    ->orWhereRaw("p.name::text ilike ? escape '\\'", [$pattern])
                    ->orWhereRaw("coalesce(p.description::text, '') ilike ? escape '\\'", [$pattern]);

                if ($locale !== null) {
                    $q->orWhereRaw("coalesce(p.name ->> ?, '') ilike ? escape '\\'", [$locale, $pattern]);
                }

                $q->orWhereExists(static fn (Builder $sv): Builder => $sv->selectRaw('1')
                    ->from($variants.' as sv')
                    ->whereColumn('sv.product_id', 'p.id')
                    ->where('sv.status', VariantStatus::Active->value)
                    ->whereRaw("sv.sku ilike ? escape '\\'", [$pattern]))
                    ->orWhereExists(static fn (Builder $si): Builder => $si->selectRaw('1')
                        ->from($index.' as si')
                        ->whereColumn('si.product_id', 'p.id')
                        ->whereRaw("si.term ilike ? escape '\\'", [$pattern]));

                return;
            }

            $q->whereRaw("lower(p.handle) like lower(?) escape '\\'", [$pattern])
                ->orWhereRaw("lower(p.name) like lower(?) escape '\\'", [$pattern])
                ->orWhereRaw("lower(coalesce(p.description, '')) like lower(?) escape '\\'", [$pattern])
                ->orWhereExists(static fn (Builder $sv): Builder => $sv->selectRaw('1')
                    ->from($variants.' as sv')
                    ->whereColumn('sv.product_id', 'p.id')
                    ->where('sv.status', VariantStatus::Active->value)
                    ->whereRaw("lower(sv.sku) like lower(?) escape '\\'", [$pattern]))
                ->orWhereExists(static fn (Builder $si): Builder => $si->selectRaw('1')
                    ->from($index.' as si')
                    ->whereColumn('si.product_id', 'p.id')
                    ->whereRaw("lower(si.term) like lower(?) escape '\\'", [$pattern]));
        });
    }

    /**
     * @param  array<string, StoredAttribute>  $attributeDefinitions
     * @return array<string, FacetDistribution>
     */
    private function computeFacets(
        ConnectionInterface $db,
        CatalogSearchQuery $query,
        array $attributeDefinitions,
        bool $isPg,
    ): array {
        if ($query->facets === []) {
            return [];
        }

        $optionCodes = array_values(array_unique(array_map(static fn (FacetRequest $f): string => $f->code, $query->facets)));
        $optionMap = $this->loadOptionIds($db, $optionCodes);
        $variantFilteredAxes = $this->variantFilteredAxes($query->filters);

        $distributions = [];

        foreach ($query->facets as $request) {
            $resolvedKind = $this->resolveFacetKind($request, $attributeDefinitions, $optionMap, $variantFilteredAxes);

            if ($resolvedKind === null) {
                $distributions[$request->code] = new FacetDistribution(
                    code: $request->code,
                    kind: $request->kind === FacetKind::Auto ? FacetKind::Option : $request->kind,
                    disjunctive: $request->disjunctive,
                    values: [],
                );

                continue;
            }

            $facetFilters = $request->disjunctive
                ? $this->excludeFacetFromFilters($query->filters, $request->code, $resolvedKind)
                : $query->filters;

            $facetConditions = $this->compileFilters($db, $facetFilters, $attributeDefinitions, $isPg);

            if ($facetConditions === null) {
                $distributions[$request->code] = new FacetDistribution(
                    code: $request->code,
                    kind: $resolvedKind,
                    disjunctive: $request->disjunctive,
                    values: [],
                );

                continue;
            }

            $distributions[$request->code] = $resolvedKind === FacetKind::Option
                ? $this->computeOptionFacet($db, $query, $request, $optionMap[$request->code], $facetConditions, $isPg)
                : $this->computeAttributeFacet($db, $query, $request, $attributeDefinitions[$request->code], $facetConditions, $isPg);
        }

        return $distributions;
    }

    /**
     * @param  array<string, StoredAttribute>  $attributeDefinitions
     * @param  array<string, string>  $optionMap
     * @param  array<string, true>  $variantFilteredAxes
     */
    private function resolveFacetKind(
        FacetRequest $request,
        array $attributeDefinitions,
        array $optionMap,
        array $variantFilteredAxes,
    ): ?FacetKind {
        if ($request->kind === FacetKind::Attribute) {
            $definition = $attributeDefinitions[$request->code] ?? throw new AttributeNotFound($request->code);

            if (! $definition->projected()) {
                throw new AttributeNotFilterable($request->code);
            }

            return FacetKind::Attribute;
        }

        if ($request->kind === FacetKind::Option) {
            return isset($optionMap[$request->code]) ? FacetKind::Option : null;
        }

        // Auto detection: honor explicit VariantFilter axis first, then filterable attribute, then option axis.
        if (isset($variantFilteredAxes[$request->code], $optionMap[$request->code])) {
            return FacetKind::Option;
        }

        $definition = $attributeDefinitions[$request->code] ?? null;

        if ($definition instanceof StoredAttribute) {
            if (! $definition->projected()) {
                throw new AttributeNotFilterable($request->code);
            }

            return FacetKind::Attribute;
        }

        return isset($optionMap[$request->code]) ? FacetKind::Option : null;
    }

    /**
     * Removes filters targeting the given facet code & kind for disjunctive facet aggregation.
     *
     * @param  list<AttributeFilter|VariantFilter>  $filters
     * @return list<AttributeFilter|VariantFilter>
     */
    private function excludeFacetFromFilters(array $filters, string $code, FacetKind $kind): array
    {
        $remaining = [];

        foreach ($filters as $filter) {
            if ($kind === FacetKind::Attribute && $filter instanceof AttributeFilter) {
                if ($filter->attribute === $code) {
                    continue;
                }

                $remaining[] = $filter;

                continue;
            }

            if ($kind === FacetKind::Option && $filter instanceof VariantFilter) {
                $axes = $filter->axes;
                unset($axes[$code]);

                if ($axes !== []) {
                    $remaining[] = VariantFilter::axes($axes);
                }

                continue;
            }

            $remaining[] = $filter;
        }

        return $remaining;
    }

    /**
     * @param  list<Closure(Builder): void>  $facetConditions
     */
    private function computeOptionFacet(
        ConnectionInterface $db,
        CatalogSearchQuery $query,
        FacetRequest $request,
        string $optionId,
        array $facetConditions,
        bool $isPg,
    ): FacetDistribution {
        $selected = $this->selectedOptionValues($query->filters, $request->code);

        $builder = $this->baseProductBuilder($db, $query, [], $isPg, includeVariantConditions: false);
        $builder->join($this->tables->table('variants').' as v', 'v.product_id', '=', 'p.id')
            ->where('v.status', VariantStatus::Active->value);

        foreach ($facetConditions as $condition) {
            $condition($builder);
        }

        $builder->join($this->tables->table('variant_option_values').' as fvov', 'fvov.variant_id', '=', 'v.id')
            ->where('fvov.option_id', $optionId)
            ->join($this->tables->table('option_values').' as fov', 'fov.id', '=', 'fvov.option_value_id')
            ->selectRaw('fov.code as facet_value, fov.label as facet_label, count(distinct p.id) as facet_count')
            ->groupBy('fov.id', 'fov.code', 'fov.label')
            ->orderByDesc('facet_count')
            ->orderBy('fov.code')
            ->limit($request->maxValues);

        $rows = $this->executeWithCategoryCte($db, $builder, $query->category);
        $values = [];

        foreach ($rows as $row) {
            $val = Rows::string($row, 'facet_value');
            $values[] = new FacetValueCount(
                value: $val,
                count: Rows::int($row, 'facet_count'),
                selected: isset($selected[$val]),
                label: Rows::localized($row, 'facet_label'),
            );
        }

        return new FacetDistribution(
            code: $request->code,
            kind: FacetKind::Option,
            disjunctive: $request->disjunctive,
            values: $values,
        );
    }

    /**
     * @param  list<Closure(Builder): void>  $facetConditions
     */
    private function computeAttributeFacet(
        ConnectionInterface $db,
        CatalogSearchQuery $query,
        FacetRequest $request,
        StoredAttribute $definition,
        array $facetConditions,
        bool $isPg,
    ): FacetDistribution {
        $selected = $this->selectedAttributeValues($query->filters, $definition);
        $indexTable = $this->tables->table('attribute_index');
        $variantsTable = $this->tables->table('variants');

        $makeBase = function () use ($db, $query, $definition, $facetConditions, $isPg, $indexTable, $variantsTable): Builder {
            $builder = $this->baseProductBuilder($db, $query, [], $isPg, includeVariantConditions: false);

            if ($facetConditions !== []) {
                $builder->join($variantsTable.' as v', 'v.product_id', '=', 'p.id')
                    ->where('v.status', VariantStatus::Active->value);

                foreach ($facetConditions as $condition) {
                    $condition($builder);
                }

                $builder->join($indexTable.' as fi', 'fi.product_id', '=', 'p.id')
                    ->where('fi.attribute_id', $definition->id)
                    ->where(static fn (Builder $owner): Builder => $owner->whereNull('fi.variant_id')->orWhereColumn('fi.variant_id', 'v.id'));
            } else {
                $builder->join($indexTable.' as fi', 'fi.product_id', '=', 'p.id')
                    ->where('fi.attribute_id', $definition->id)
                    ->where(static function (Builder $owner) use ($variantsTable): void {
                        $owner->whereNull('fi.variant_id')
                            ->orWhereExists(static fn (Builder $av): Builder => $av->selectRaw('1')
                                ->from($variantsTable.' as av')
                                ->whereColumn('av.id', 'fi.variant_id')
                                ->where('av.status', VariantStatus::Active->value));
                    });
            }

            return $builder;
        };

        $column = self::indexColumnForType($definition->type);
        $builder = $makeBase();
        $min = null;
        $max = null;
        $values = [];

        if ($column === 'choice_id') {
            $builder->join($this->tables->table('attribute_choices').' as fac', 'fac.id', '=', 'fi.choice_id')
                ->selectRaw('fac.code as facet_value, fac.label as facet_label, count(distinct p.id) as facet_count')
                ->groupBy('fac.id', 'fac.code', 'fac.label')
                ->orderByDesc('facet_count')
                ->orderBy('fac.code')
                ->limit($request->maxValues);

            foreach ($this->executeWithCategoryCte($db, $builder, $query->category) as $row) {
                $val = Rows::string($row, 'facet_value');
                $values[] = new FacetValueCount(
                    value: $val,
                    count: Rows::int($row, 'facet_count'),
                    selected: isset($selected[$val]),
                    label: Rows::localized($row, 'facet_label'),
                );
            }
        } else {
            $builder->whereNotNull("fi.{$column}")
                ->selectRaw("fi.{$column} as facet_value, count(distinct p.id) as facet_count")
                ->groupBy("fi.{$column}")
                ->orderByDesc('facet_count')
                ->orderBy("fi.{$column}")
                ->limit($request->maxValues);

            foreach ($this->executeWithCategoryCte($db, $builder, $query->category) as $row) {
                $val = $this->formatFacetValue($definition, get_object_vars($row)['facet_value'] ?? null);
                $values[] = new FacetValueCount(
                    value: $val,
                    count: Rows::int($row, 'facet_count'),
                    selected: isset($selected[$val]),
                );
            }

            if ($column === 'num' || $column === 'day') {
                $boundsBuilder = $makeBase()->whereNotNull("fi.{$column}");
                $minExpr = $column === 'num' && ! $isPg ? 'min(cast(fi.num as numeric))' : "min(fi.{$column})";
                $maxExpr = $column === 'num' && ! $isPg ? 'max(cast(fi.num as numeric))' : "max(fi.{$column})";
                $boundsBuilder->selectRaw("{$minExpr} as min_val, {$maxExpr} as max_val");

                $boundRow = $this->executeWithCategoryCte($db, $boundsBuilder, $query->category)[0] ?? null;

                if (is_object($boundRow)) {
                    $rawMin = get_object_vars($boundRow)['min_val'] ?? null;
                    $rawMax = get_object_vars($boundRow)['max_val'] ?? null;

                    if ($rawMin !== null && $rawMin !== '') {
                        $min = $this->formatFacetValue($definition, $rawMin);
                    }

                    if ($rawMax !== null && $rawMax !== '') {
                        $max = $this->formatFacetValue($definition, $rawMax);
                    }
                }
            }
        }

        // Re-sort deterministically by count desc, then formatted value asc (handles canonicalized strings).
        usort($values, static function (FacetValueCount $a, FacetValueCount $b): int {
            $countCmp = $b->count <=> $a->count;

            return $countCmp !== 0 ? $countCmp : strcmp($a->value, $b->value);
        });

        return new FacetDistribution(
            code: $request->code,
            kind: FacetKind::Attribute,
            disjunctive: $request->disjunctive,
            values: $values,
            min: $min,
            max: $max,
        );
    }

    /**
     * @param  list<AttributeFilter|VariantFilter>  $filters
     * @param  array<string, StoredAttribute>  $definitions
     * @return list<Closure(Builder): void>|null null when an unknown option/value makes no variant matchable
     */
    private function compileFilters(
        ConnectionInterface $db,
        array $filters,
        array $definitions,
        bool $isPg,
    ): ?array {
        $attributeFilters = [];
        $variantFilters = [];

        foreach ($filters as $filter) {
            if ($filter instanceof AttributeFilter) {
                $attributeFilters[] = $filter;
            } else {
                $variantFilters[] = $filter;
            }
        }

        $conditions = [];

        foreach ($attributeFilters as $filter) {
            $definition = $definitions[$filter->attribute] ?? throw new AttributeNotFound($filter->attribute);
            array_push($conditions, ...$this->compileAttributeFilter($filter, $definition, $isPg));
        }

        $optionValueIds = $this->resolveOptionValueIds($db, $variantFilters);

        if ($optionValueIds === null) {
            return null;
        }

        $variantValues = $this->tables->table('variant_option_values');

        foreach ($optionValueIds as $valueId) {
            $conditions[] = static function (Builder $v) use ($variantValues, $valueId): void {
                $v->whereExists(static fn (Builder $x): Builder => $x->selectRaw('1')
                    ->from($variantValues.' as x')
                    ->whereColumn('x.variant_id', 'v.id')
                    ->where('x.option_value_id', $valueId));
            };
        }

        return $conditions;
    }

    /** @return list<Closure(Builder): void> */
    private function compileAttributeFilter(AttributeFilter $filter, StoredAttribute $definition, bool $isPg): array
    {
        if (! $definition->projected()) {
            throw new AttributeNotFilterable($filter->attribute);
        }

        $type = $this->contributions->get(AttributeType::KIND, $definition->type, AttributeType::class);

        if (! in_array($filter->operator, $type->operators(), true)) {
            throw new InvalidCommand('filters', "'{$filter->attribute}' does not support the {$filter->operator->value} filter", 'catalog.unsupported_filter');
        }

        if ($definition->type === 'text') {
            return [$this->compileTextFilter($filter, $type, $definition, $isPg)];
        }

        return match ($filter->operator) {
            FilterOperator::Equals => array_map(
                fn (IndexFact $fact): Closure => $this->indexExists($definition, function (Builder $i) use ($fact, $isPg): void {
                    [$column, $value] = self::factColumn($fact, $isPg);

                    if ($isPg) {
                        $i->whereRaw("i.{$column} = ?::".self::PG_CASTS[$column], [$value]);
                    } elseif ($column === 'num') {
                        $i->whereRaw('cast(i.num as numeric) = cast(? as numeric)', [$value]);
                    } else {
                        $i->where("i.{$column}", $value);
                    }
                }),
                $this->normalizeFacts($type, $definition, $filter->values[0]),
            ),
            FilterOperator::In => [$this->indexExists($definition, $this->compileInMatch($type, $definition, $filter->values, $isPg))],
            FilterOperator::Range => [$this->indexExists($definition, $this->compileRangeMatch($type, $definition, $filter->values[0], $filter->values[1] ?? null, $isPg))],
        };
    }

    /** @return Closure(Builder): void */
    private function compileTextFilter(AttributeFilter $filter, AttributeType $type, StoredAttribute $definition, bool $isPg): Closure
    {
        $values = [];

        foreach ($filter->values as $input) {
            $value = $type->normalize($input, $definition->definition);

            if (! is_string($value)) {
                throw new InvalidAttributeValue($filter->attribute, 'expected a text value');
            }

            $values[] = $value;
        }

        return $this->indexExists($definition, static function (Builder $i) use ($values, $filter, $isPg): void {
            $i->where(static function (Builder $any) use ($values, $filter, $isPg): void {
                foreach ($values as $value) {
                    if ($isPg) {
                        $any->orWhereRaw(
                            '(i.term = ?::varchar and (case when i.variant_id is null then p.attributes else v.attributes end) ->> ? = ?::text)',
                            [mb_substr($value, 0, 255), $filter->attribute, $value],
                        );
                    } else {
                        $jsonPath = '$."'.str_replace('"', '\\"', $filter->attribute).'"';
                        $any->orWhereRaw(
                            '(i.term = ? and json_extract(case when i.variant_id is null then p.attributes else v.attributes end, ?) = ?)',
                            [mb_substr($value, 0, 255), $jsonPath, $value],
                        );
                    }
                }
            });
        });
    }

    /**
     * @param  list<mixed>  $values
     * @return Closure(Builder): void
     */
    private function compileInMatch(AttributeType $type, StoredAttribute $definition, array $values, bool $isPg): Closure
    {
        $byColumn = [];

        foreach ($values as $input) {
            foreach ($this->normalizeFacts($type, $definition, $input) as $fact) {
                [$column, $value] = self::factColumn($fact, $isPg);
                $byColumn[$column][(string) $value] = $value;
            }
        }

        return static function (Builder $i) use ($byColumn, $isPg): void {
            $i->where(static function (Builder $any) use ($byColumn, $isPg): void {
                foreach ($byColumn as $column => $columnValues) {
                    $bound = array_values($columnValues);

                    if ($isPg) {
                        $placeholders = implode(', ', array_fill(0, count($bound), '?::'.self::PG_CASTS[$column]));
                        $any->orWhereRaw("i.{$column} in ({$placeholders})", $bound);
                    } elseif ($column === 'num') {
                        $placeholders = implode(', ', array_fill(0, count($bound), 'cast(? as numeric)'));
                        $any->orWhereRaw("cast(i.num as numeric) in ({$placeholders})", $bound);
                    } else {
                        $any->orWhereIn("i.{$column}", $bound);
                    }
                }
            });
        };
    }

    /** @return Closure(Builder): void */
    private function compileRangeMatch(AttributeType $type, StoredAttribute $definition, mixed $min, mixed $max, bool $isPg): Closure
    {
        $bounds = [];

        foreach (['>=' => $min, '<=' => $max] as $operator => $input) {
            if ($input === null) {
                continue;
            }

            $facts = $this->normalizeFacts($type, $definition, $input);
            [$column, $value] = count($facts) === 1 ? self::factColumn($facts[0], $isPg) : [null, ''];

            if ($column !== 'num' && $column !== 'day') {
                throw new InvalidCommand('filters', "a bound of '{$definition->definition->code}' must be a single number or date", 'catalog.invalid_filter');
            }

            $bounds[] = [$column, $operator, $value];
        }

        return static function (Builder $i) use ($bounds, $isPg): void {
            foreach ($bounds as [$column, $operator, $value]) {
                if ($isPg) {
                    $i->whereRaw("i.{$column} {$operator} ?::".self::PG_CASTS[$column], [$value]);
                } elseif ($column === 'num') {
                    $i->whereRaw("cast(i.num as numeric) {$operator} cast(? as numeric)", [$value]);
                } else {
                    $i->where("i.{$column}", $operator, $value);
                }
            }
        };
    }

    /**
     * @param  Closure(Builder): void  $match
     * @return Closure(Builder): void
     */
    private function indexExists(StoredAttribute $definition, Closure $match): Closure
    {
        $index = $this->tables->table('attribute_index');

        return static function (Builder $v) use ($index, $definition, $match): void {
            $v->whereExists(static function (Builder $i) use ($index, $definition, $match): void {
                $i->selectRaw('1')
                    ->from($index.' as i')
                    ->where('i.attribute_id', $definition->id)
                    ->whereColumn('i.product_id', 'p.id')
                    ->where(static fn (Builder $owner): Builder => $owner->whereNull('i.variant_id')->orWhereColumn('i.variant_id', 'v.id'));
                $match($i);
            });
        };
    }

    /** @return non-empty-list<IndexFact> */
    private function normalizeFacts(AttributeType $type, StoredAttribute $definition, mixed $input): array
    {
        try {
            $value = $type->normalize($input, $definition->definition);
        } catch (InvalidAttributeValue $e) {
            if (is_array($input)) {
                throw $e;
            }

            try {
                $value = $type->normalize([$input], $definition->definition);
            } catch (InvalidAttributeValue) {
                throw $e;
            }
        }

        $facts = $type->index($value, $definition->definition);

        if ($facts === []) {
            throw new InvalidCommand('filters', "the value of '{$definition->definition->code}' gives nothing to filter by", 'catalog.invalid_filter');
        }

        return $facts;
    }

    /** @return array{0: key-of<self::PG_CASTS>, 1: string|int} */
    private static function factColumn(IndexFact $fact, bool $isPg): array
    {
        return match (true) {
            $fact->choice !== null => ['choice_id', $fact->choice->value],
            $fact->number !== null => ['num', $fact->number],
            $fact->flag !== null => ['flag', $isPg ? ($fact->flag ? 'true' : 'false') : ($fact->flag ? 1 : 0)],
            $fact->day !== null => ['day', $fact->day],
            default => ['term', (string) $fact->term],
        };
    }

    /**
     * @param  list<VariantFilter>  $filters
     * @return list<string>|null
     */
    private function resolveOptionValueIds(ConnectionInterface $db, array $filters): ?array
    {
        $pairs = [];

        foreach ($filters as $filter) {
            foreach ($filter->axes as $option => $value) {
                $pairs["{$option}\0{$value}"] = [$option, $value];
            }
        }

        if ($pairs === []) {
            return [];
        }

        $rows = $db->table($this->tables->table('options').' as o')
            ->join($this->tables->table('option_values').' as ov', 'ov.option_id', '=', 'o.id')
            ->where(static function (Builder $any) use ($pairs): void {
                foreach ($pairs as [$option, $value]) {
                    $any->orWhere(static fn (Builder $pair): Builder => $pair->where('o.code', $option)->where('ov.code', $value));
                }
            })
            ->select('o.code as option', 'ov.code as value', 'ov.id')
            ->get();

        $ids = [];

        foreach ($rows as $row) {
            $ids[Rows::string($row, 'option')."\0".Rows::string($row, 'value')] = Rows::string($row, 'id');
        }

        return count($ids) === count($pairs) ? array_values($ids) : null;
    }

    /**
     * @param  list<string>  $codes
     * @return array<string, string> option code => option id
     */
    private function loadOptionIds(ConnectionInterface $db, array $codes): array
    {
        if ($codes === []) {
            return [];
        }

        $rows = $db->table($this->tables->table('options'))
            ->whereIn('code', $codes)
            ->select('id', 'code')
            ->get();

        $map = [];

        foreach ($rows as $row) {
            $map[Rows::string($row, 'code')] = Rows::string($row, 'id');
        }

        return $map;
    }

    /** @return list<string> */
    private function collectAttributeCodes(CatalogSearchQuery $query): array
    {
        $codes = [];

        foreach ($query->filters as $filter) {
            if ($filter instanceof AttributeFilter) {
                $codes[] = $filter->attribute;
            }
        }

        foreach ($query->facets as $facet) {
            if ($facet->kind !== FacetKind::Option) {
                $codes[] = $facet->code;
            }
        }

        return array_values(array_unique($codes));
    }

    /**
     * @param  list<AttributeFilter|VariantFilter>  $filters
     * @return array<string, true>
     */
    private function variantFilteredAxes(array $filters): array
    {
        $axes = [];

        foreach ($filters as $filter) {
            if ($filter instanceof VariantFilter) {
                foreach (array_keys($filter->axes) as $axis) {
                    $axes[$axis] = true;
                }
            }
        }

        return $axes;
    }

    /**
     * @param  list<AttributeFilter|VariantFilter>  $filters
     * @return array<string, true>
     */
    private function selectedOptionValues(array $filters, string $optionCode): array
    {
        $selected = [];

        foreach ($filters as $filter) {
            if ($filter instanceof VariantFilter && isset($filter->axes[$optionCode])) {
                $selected[$filter->axes[$optionCode]] = true;
            }
        }

        return $selected;
    }

    /**
     * @param  list<AttributeFilter|VariantFilter>  $filters
     * @return array<string, true>
     */
    private function selectedAttributeValues(array $filters, StoredAttribute $definition): array
    {
        $selected = [];
        $type = $this->contributions->has(AttributeType::KIND, $definition->type)
            ? $this->contributions->get(AttributeType::KIND, $definition->type, AttributeType::class)
            : null;

        if ($type === null) {
            return [];
        }

        $choiceById = [];

        foreach ($definition->definition->choices as $choice) {
            $choiceById[$choice->id->value] = $choice->code;
        }

        foreach ($filters as $filter) {
            if (! $filter instanceof AttributeFilter || $filter->attribute !== $definition->definition->code) {
                continue;
            }

            if ($filter->operator !== FilterOperator::Equals && $filter->operator !== FilterOperator::In) {
                continue;
            }

            foreach ($filter->values as $input) {
                foreach ($this->normalizeFacts($type, $definition, $input) as $fact) {
                    $value = match (true) {
                        $fact->choice !== null => $choiceById[$fact->choice->value] ?? null,
                        $fact->number !== null => $this->formatFacetValue($definition, $fact->number),
                        $fact->flag !== null => $fact->flag ? 'true' : 'false',
                        $fact->day !== null => $fact->day,
                        default => $fact->term,
                    };

                    if ($value !== null) {
                        $selected[$value] = true;
                    }
                }
            }
        }

        return $selected;
    }

    /** @return list<object> */
    private function executeWithCategoryCte(ConnectionInterface $db, Builder $builder, ?CategoryId $category): array
    {
        if (! $category instanceof CategoryId) {
            return array_values(array_filter($builder->get()->all(), is_object(...)));
        }

        $categories = $builder->getGrammar()->wrapTable($this->tables->table('categories'));
        $subtree = "with recursive sub as (select id from {$categories} where id = ?"
            ." union all select c.id from {$categories} c join sub on c.parent_id = sub.id) ";

        return array_values(array_filter(
            $db->select($subtree.$builder->toSql(), [$category->value, ...$builder->getBindings()]),
            is_object(...),
        ));
    }

    /**
     * @param  list<object>  $rows
     * @return list<ProductView>
     */
    private function hydrateViews(ConnectionInterface $db, array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $documents = [];
        $codes = [];

        foreach ($rows as $row) {
            $document = Documents::decode(Rows::string($row, 'attributes'));
            $documents[Rows::string($row, 'id')] = $document;
            array_push($codes, ...array_map(strval(...), array_keys($document)));
        }

        $retired = [];

        if ($codes !== []) {
            $retiredCodes = $db->table($this->tables->table('attributes'))
                ->whereIn('code', array_values(array_unique($codes)))
                ->where('status', AttributeStatus::Retired->value)
                ->pluck('code')
                ->all();
            $retired = array_fill_keys(array_filter($retiredCodes, is_string(...)), true);
        }

        $axes = [];
        $axisRows = $db->table($this->tables->table('product_options').' as po')
            ->join($this->tables->table('options').' as o', 'o.id', '=', 'po.option_id')
            ->whereIn('po.product_id', array_keys($documents))
            ->orderBy('po.position')
            ->select('po.product_id', 'o.code')
            ->get();

        foreach ($axisRows as $axis) {
            $axes[Rows::string($axis, 'product_id')][] = Rows::string($axis, 'code');
        }

        return array_map(static function (object $row) use ($documents, $retired, $axes): ProductView {
            $id = Rows::string($row, 'id');
            $attributes = array_diff_key($documents[$id], $retired);
            ksort($attributes, SORT_STRING);

            return new ProductView(
                new ProductId($id),
                new ProductTypeId(Rows::string($row, 'product_type_id')),
                Rows::string($row, 'handle'),
                Rows::localized($row, 'name'),
                Rows::nullableString($row, 'description') === null ? null : Rows::localized($row, 'description'),
                ProductStatus::from(Rows::string($row, 'status')),
                $attributes,
                $axes[$id] ?? [],
                Rows::date($row, 'created_at'),
                Rows::date($row, 'updated_at'),
            );
        }, $rows);
    }

    private function formatFacetValue(StoredAttribute $definition, mixed $raw): string
    {
        if ($definition->type === 'boolean') {
            $bool = match (true) {
                is_bool($raw) => $raw,
                is_int($raw) => $raw !== 0,
                is_string($raw) => in_array(strtolower($raw), ['1', 't', 'true'], true),
                default => false,
            };

            return $bool ? 'true' : 'false';
        }

        $text = is_scalar($raw) ? (string) $raw : '';

        if ($definition->type === 'integer') {
            return BigDecimal::of($text)->toScale(0)->__toString();
        }

        if ($definition->type === 'decimal' || $definition->type === 'measure') {
            $scale = $definition->definition->config['scale'] ?? null;

            return is_int($scale) && $scale >= 0
                ? BigDecimal::of($text)->toScale($scale)->__toString()
                : BigDecimal::of($text)->strippedOfTrailingZeros()->__toString();
        }

        return $text;
    }

    /** @return 'choice_id'|'num'|'flag'|'day'|'term' */
    private static function indexColumnForType(string $type): string
    {
        return match ($type) {
            'enum', 'multi_enum' => 'choice_id',
            'integer', 'decimal', 'measure' => 'num',
            'boolean' => 'flag',
            'date' => 'day',
            default => 'term',
        };
    }

    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    private function isPostgres(ConnectionInterface $db): bool
    {
        return $db instanceof Connection && $db->getDriverName() === 'pgsql';
    }

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->config->connection);
    }
}
