<?php

declare(strict_types=1);

namespace Commerz\Testkit\Fakes;

use Commerz\Catalog\Products\Data\ProductId;
use Commerz\Catalog\Products\Data\ProductView;
use Commerz\Catalog\Search\Contracts\CatalogSearchPort;
use Commerz\Catalog\Search\Data\CatalogSearchQuery;
use Commerz\Catalog\Search\Data\CatalogSearchResult;
use Commerz\Catalog\Search\Data\FacetDistribution;
use Commerz\Catalog\Search\Enums\FacetKind;
use Commerz\Catalog\Search\Enums\SearchSort;
use Commerz\Pagination\Cursor;
use Commerz\Pagination\Page;
use Override;
use Packman\Attributes\Api;

/**
 * In-memory `CatalogSearchPort` for catalog search tests and adapter certification.
 */
#[Api(since: '0.1')]
final class FakeSearchEngine implements CatalogSearchPort
{
    /** @var array<string, ProductView> */
    private array $products = [];

    /** @var array<string, bool> */
    private array $indexed = [];

    /** @var list<CatalogSearchQuery> */
    public private(set) array $queries = [];

    /** @var list<ProductId> */
    public private(set) array $indexedIds = [];

    /** @var list<ProductId> */
    public private(set) array $removedIds = [];

    public function add(ProductView ...$products): self
    {
        foreach ($products as $product) {
            $id = (string) $product->id;
            $this->products[$id] = $product;
            $this->indexed[$id] = true;
        }

        return $this;
    }

    #[Override]
    public function search(CatalogSearchQuery $query): CatalogSearchResult
    {
        $this->queries[] = $query;

        $matched = [];

        foreach ($this->products as $id => $product) {
            if (! ($this->indexed[$id] ?? false)) {
                continue;
            }

            if ($query->type !== null && (string) $product->type !== (string) $query->type) {
                continue;
            }

            if ($query->status !== null && $product->status !== $query->status) {
                continue;
            }

            if ($query->term !== null && ! $this->matchesTerm($product, $query->term, $query->locale)) {
                continue;
            }

            $matched[] = $product;
        }

        usort($matched, static function (ProductView $a, ProductView $b) use ($query): int {
            $cmp = match ($query->sort) {
                SearchSort::HandleDesc => strcmp($b->handle, $a->handle),
                SearchSort::Newest => $b->createdAt <=> $a->createdAt,
                SearchSort::Oldest => $a->createdAt <=> $b->createdAt,
                default => strcmp($a->handle, $b->handle),
            };

            if ($cmp !== 0) {
                return $cmp;
            }

            return $query->sort === SearchSort::HandleDesc || $query->sort === SearchSort::Newest
                ? strcmp((string) $b->id, (string) $a->id)
                : strcmp((string) $a->id, (string) $b->id);
        });

        $total = count($matched);
        $offset = 0;

        if ($query->after !== null) {
            $afterId = $query->after->position();

            foreach ($matched as $index => $product) {
                if ((string) $product->id === $afterId) {
                    $offset = $index + 1;

                    break;
                }
            }
        }

        $slice = array_slice($matched, $offset, $query->first);
        $hasMore = ($offset + count($slice)) < $total;
        $nextCursor = $hasMore && $slice !== []
            ? Cursor::after((string) $slice[count($slice) - 1]->id)
            : null;

        $facets = [];

        foreach ($query->facets as $facetRequest) {
            $facets[$facetRequest->code] = new FacetDistribution(
                code: $facetRequest->code,
                kind: $facetRequest->kind === FacetKind::Auto ? FacetKind::Attribute : $facetRequest->kind,
                disjunctive: $facetRequest->disjunctive,
                values: [],
            );
        }

        return new CatalogSearchResult(
            page: new Page($slice, $nextCursor),
            total: $total,
            facets: $facets,
        );
    }

    /** @param  list<ProductId>  $productIds */
    #[Override]
    public function indexProducts(array $productIds): void
    {
        foreach ($productIds as $productId) {
            $this->indexedIds[] = $productId;
            $this->indexed[(string) $productId] = true;
        }
    }

    /** @param  list<ProductId>  $productIds */
    #[Override]
    public function removeProducts(array $productIds): void
    {
        foreach ($productIds as $productId) {
            $this->removedIds[] = $productId;
            unset($this->indexed[(string) $productId]);
        }
    }

    private function matchesTerm(ProductView $product, string $term, ?string $locale): bool
    {
        $needle = mb_strtolower($term, 'UTF-8');

        if (str_contains(mb_strtolower($product->handle, 'UTF-8'), $needle)) {
            return true;
        }

        $name = $product->name->resolve($locale ?? 'en');

        return str_contains(mb_strtolower($name, 'UTF-8'), $needle);
    }
}
