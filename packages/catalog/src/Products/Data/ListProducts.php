<?php

declare(strict_types=1);

namespace Commerz\Catalog\Products\Data;

use Commerz\Attributes\Api;
use Commerz\Catalog\Categories\Data\CategoryId;
use Commerz\Catalog\Products\Enums\ProductStatus;
use Commerz\Catalog\ProductTypes\Data\ProductTypeId;
use Commerz\Errors\InvalidCommand;
use Commerz\Pagination\Cursor;
use Commerz\Stores\Data\ChannelId;
use DateTimeImmutable;

/**
 * Products ordered by handle. With a channel: active products published there and available at `at` (default now);
 * without one: products of `status`, by default every status but archived. A category includes its descendants.
 * All `filters` hold on one and the same active variant (CAT-03).
 */
#[Api(since: '0.1')]
final readonly class ListProducts
{
    /** Filters one query takes. */
    public const int MAX_FILTERS = 20;

    /** @var list<AttributeFilter|VariantFilter> */
    public array $filters;

    /**
     * @param  array<array-key, mixed>  $filters  a list of AttributeFilter|VariantFilter
     */
    public function __construct(
        public ?ChannelId $channel = null,
        public ?ProductTypeId $type = null,
        public ?CategoryId $category = null,
        public ?ProductStatus $status = null,
        array $filters = [],
        public ?DateTimeImmutable $at = null,
        public int $first = 24,
        public ?Cursor $after = null,
    ) {
        if ($first < 1 || $first > 200) {
            throw new InvalidCommand('first', 'must be from 1 to 200', 'common.invalid_page_size');
        }

        if (! array_is_list($filters) || count($filters) > self::MAX_FILTERS) {
            throw new InvalidCommand('filters', 'must be a list of at most '.self::MAX_FILTERS.' filters', 'catalog.invalid_filter');
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
