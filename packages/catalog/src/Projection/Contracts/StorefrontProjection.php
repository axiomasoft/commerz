<?php

declare(strict_types=1);

namespace Commerz\Catalog\Projection\Contracts;

use Commerz\Catalog\Products\Data\ProductId;
use Commerz\Catalog\Projection\Data\ListStorefrontProducts;
use Commerz\Catalog\Projection\Data\StorefrontProductView;
use Commerz\Catalog\Projection\Exceptions\StorefrontProductNotFound;
use Commerz\Pagination\Page;
use Commerz\Stores\Data\ChannelId;
use Packman\Attributes\Api;

/**
 * Denormalized single-row storefront read model over `cmz_storefront_products`, kept up to date via Outbox events
 * (`catalog.*`, `pricing.*`, `inventory.*`) or explicit rebuild commands.
 */
#[Api(since: '0.1')]
interface StorefrontProjection
{
    /** Single-row read by `(channel_id, locale, product_id)`, or null when not projected. */
    public function find(ChannelId $channel, string $locale, ProductId $product): ?StorefrontProductView;

    /**
     * Single-row read by `(channel_id, locale, product_id)`.
     *
     * @throws StorefrontProductNotFound
     */
    public function get(ChannelId $channel, string $locale, ProductId $product): StorefrontProductView;

    /** Single-table storefront read by `(channel_id, locale, handle)`. */
    public function findByHandle(ChannelId $channel, string $locale, string $handle): ?StorefrontProductView;

    /** @return Page<StorefrontProductView> */
    public function list(ListStorefrontProducts $query): Page;

    /**
     * Materializes (or deletes if unpublished/archived) storefront rows for one product across its listed channels
     * (or a single `$channel` when specified). Returns the number of projected rows written.
     */
    public function projectProduct(ProductId $product, ?ChannelId $channel = null): int;

    /**
     * Rebuilds all storefront rows for an entire channel across all of its configured locales.
     * Returns the number of projected rows written.
     */
    public function projectChannel(ChannelId $channel): int;
}
