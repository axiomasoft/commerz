<?php

declare(strict_types=1);

namespace Commerz\Catalog\Collections\Data;

use Commerz\Catalog\Products\Data\AttributeFilter;
use Commerz\Catalog\Products\Data\ListProducts;
use Commerz\Catalog\Products\Data\VariantFilter;
use Commerz\Catalog\Products\Enums\ProductStatus;
use Commerz\Errors\InvalidCommand;
use Commerz\Pagination\Cursor;
use Commerz\Stores\Data\ChannelId;
use DateTimeImmutable;
use Packman\Attributes\Api;

/**
 * Products of a collection: ordered by `(position, handle)` for manual collections and by `handle` for smart
 * collections, optionally narrowed to a channel/window and extra storefront filters.
 */
#[Api(since: '0.1')]
final readonly class ListCollectionProducts
{
    /** @var list<AttributeFilter|VariantFilter> */
    public array $filters;

    /**
     * @param  array<array-key, mixed>  $filters  optional additional AttributeFilter|VariantFilter conditions
     */
    public function __construct(
        public CollectionId $collection,
        public ?ChannelId $channel = null,
        public ?ProductStatus $status = null,
        array $filters = [],
        public ?DateTimeImmutable $at = null,
        public int $first = 24,
        public ?Cursor $after = null,
    ) {
        if ($first < 1 || $first > 200) {
            throw new InvalidCommand('first', 'must be from 1 to 200', 'common.invalid_page_size');
        }

        if (! array_is_list($filters) || count($filters) > ListProducts::MAX_FILTERS) {
            throw new InvalidCommand('filters', 'must be a list of at most '.ListProducts::MAX_FILTERS.' filters', 'catalog.invalid_filter');
        }

        $this->filters = array_map(static fn (mixed $filter): AttributeFilter|VariantFilter => $filter instanceof AttributeFilter || $filter instanceof VariantFilter
            ? $filter
            : throw new InvalidCommand('filters', 'every filter must be an AttributeFilter or a VariantFilter', 'catalog.invalid_filter'), $filters);

        if ($channel === null && $at instanceof DateTimeImmutable) {
            throw new InvalidCommand('at', 'availability is checked in a channel; give the channel too', 'catalog.invalid_filter');
        }

        if ($channel instanceof ChannelId && $status instanceof ProductStatus && $status !== ProductStatus::Active) {
            throw new InvalidCommand('status', 'a channel lists active products only', 'catalog.invalid_filter');
        }
    }
}
