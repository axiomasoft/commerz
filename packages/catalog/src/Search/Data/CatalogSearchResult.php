<?php

declare(strict_types=1);

namespace Commerz\Catalog\Search\Data;

use Commerz\Catalog\Products\Data\ProductView;
use Commerz\Pagination\Cursor;
use Commerz\Pagination\Page;
use Packman\Attributes\Api;

/** Search response: one keyset-paginated page of products, total match count, and facet distributions. */
#[Api(since: '0.1')]
final readonly class CatalogSearchResult
{
    /**
     * @param  Page<ProductView>  $page
     * @param  array<string, FacetDistribution>  $facets  keyed by facet code
     */
    public function __construct(
        public Page $page,
        public int $total,
        public array $facets = [],
    ) {}

    public function facet(string $code): ?FacetDistribution
    {
        return $this->facets[$code] ?? null;
    }

    /** @return list<ProductView> */
    public function items(): array
    {
        return $this->page->items;
    }

    public function nextCursor(): ?Cursor
    {
        return $this->page->next;
    }

    public function hasMore(): bool
    {
        return $this->page->hasMore();
    }
}
