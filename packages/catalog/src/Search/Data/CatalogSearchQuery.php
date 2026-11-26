<?php

declare(strict_types=1);

namespace Commerz\Catalog\Search\Data;

use Commerz\Catalog\Categories\Data\CategoryId;
use Commerz\Catalog\Products\Data\AttributeFilter;
use Commerz\Catalog\Products\Data\VariantFilter;
use Commerz\Catalog\Products\Enums\ProductStatus;
use Commerz\Catalog\ProductTypes\Data\ProductTypeId;
use Commerz\Catalog\Search\Enums\SearchSort;
use Commerz\Errors\InvalidCommand;
use Commerz\Pagination\Cursor;
use Commerz\Stores\Data\ChannelId;
use DateTimeImmutable;
use Packman\Attributes\Api;

/**
 * Catalog search query with optional text search, attribute/variant filters (CAT-03), disjunctive facet requests,
 * deterministic sorting, and keyset cursor pagination.
 */
#[Api(since: '0.1')]
final readonly class CatalogSearchQuery
{
    public const int MAX_FILTERS = 20;

    public const int MAX_FACETS = 20;

    public const int MAX_PAGE_SIZE = 200;

    public const int MAX_TERM_LENGTH = 255;

    public ?string $term;

    public ?string $locale;

    /** @var list<AttributeFilter|VariantFilter> */
    public array $filters;

    /** @var list<FacetRequest> */
    public array $facets;

    /**
     * @param  array<array-key, mixed>  $filters  list of AttributeFilter|VariantFilter
     * @param  array<array-key, mixed>  $facets  list of FacetRequest or facet code strings
     */
    public function __construct(
        ?string $term = null,
        ?string $locale = null,
        public ?ChannelId $channel = null,
        public ?ProductTypeId $type = null,
        public ?CategoryId $category = null,
        public ?ProductStatus $status = null,
        array $filters = [],
        array $facets = [],
        public SearchSort $sort = SearchSort::HandleAsc,
        public ?DateTimeImmutable $at = null,
        public int $first = 24,
        public ?Cursor $after = null,
    ) {
        if ($first < 1 || $first > self::MAX_PAGE_SIZE) {
            throw new InvalidCommand('first', 'must be from 1 to '.self::MAX_PAGE_SIZE, 'common.invalid_page_size');
        }

        if ($term !== null) {
            $trimmed = trim($term);

            if ($trimmed === '') {
                $this->term = null;
            } elseif (mb_strlen($trimmed) > self::MAX_TERM_LENGTH) {
                throw new InvalidCommand('term', 'must be at most '.self::MAX_TERM_LENGTH.' characters', 'catalog.invalid_filter');
            } else {
                $this->term = $trimmed;
            }
        } else {
            $this->term = null;
        }

        if ($locale !== null) {
            $trimmedLocale = trim($locale);

            if ($trimmedLocale === '' || preg_match('/\A[a-zA-Z]{2,8}(?:-[a-zA-Z0-9]{1,8})*\z/', $trimmedLocale) !== 1) {
                throw new InvalidCommand('locale', "'{$locale}' is not a valid BCP 47 locale tag", 'catalog.invalid_filter');
            }

            $this->locale = $trimmedLocale;
        } else {
            $this->locale = null;
        }

        if (! array_is_list($filters) || count($filters) > self::MAX_FILTERS) {
            throw new InvalidCommand('filters', 'must be a list of at most '.self::MAX_FILTERS.' filters', 'catalog.invalid_filter');
        }

        $this->filters = array_map(
            static fn (mixed $filter): AttributeFilter|VariantFilter => $filter instanceof AttributeFilter || $filter instanceof VariantFilter
                ? $filter
                : throw new InvalidCommand('filters', 'every filter must be an AttributeFilter or a VariantFilter', 'catalog.invalid_filter'),
            $filters,
        );

        if (! array_is_list($facets) || count($facets) > self::MAX_FACETS) {
            throw new InvalidCommand('facets', 'must be a list of at most '.self::MAX_FACETS.' facets', 'catalog.invalid_filter');
        }

        $normalizedFacets = [];
        $seenFacets = [];

        foreach ($facets as $facet) {
            $request = match (true) {
                $facet instanceof FacetRequest => $facet,
                is_string($facet) => FacetRequest::auto($facet),
                default => throw new InvalidCommand('facets', 'every facet must be a FacetRequest or code string', 'catalog.invalid_filter'),
            };

            if (isset($seenFacets[$request->code])) {
                throw new InvalidCommand('facets', "duplicate facet '{$request->code}'", 'catalog.invalid_filter');
            }

            $seenFacets[$request->code] = true;
            $normalizedFacets[] = $request;
        }

        $this->facets = $normalizedFacets;

        if ($channel === null && $at instanceof DateTimeImmutable) {
            throw new InvalidCommand('at', 'availability is checked in a channel; give the channel too', 'catalog.invalid_filter');
        }

        if ($channel instanceof ChannelId && $status instanceof ProductStatus && $status !== ProductStatus::Active) {
            throw new InvalidCommand('status', 'a channel lists active products only', 'catalog.invalid_filter');
        }
    }
}
