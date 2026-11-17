<?php

declare(strict_types=1);

namespace Commerz\Catalog\Listings\Contracts;

use Commerz\Catalog\Listings\Data\ListingView;
use Commerz\Catalog\Listings\Data\PublishProduct;
use Commerz\Catalog\Listings\Data\UnpublishProduct;
use Commerz\Catalog\Listings\Exceptions\ListingNotFound;
use Commerz\Catalog\Products\Data\ProductId;
use Commerz\Catalog\Products\Exceptions\ProductArchived;
use Commerz\Catalog\Products\Exceptions\ProductNotFound;
use Commerz\Stores\Data\ChannelId;
use Commerz\Stores\Exceptions\ChannelArchived;
use Commerz\Stores\Exceptions\ChannelNotFound;
use Packman\Attributes\Api;

/**
 * Listings: in which channels a product is offered and when. The channel is checked through the core `Channels`
 * contract at publication; archiving a channel later leaves its listings in place (the core refuses sales there).
 */
#[Api(since: '0.1')]
interface Listings
{
    /** @throws ProductNotFound|ProductArchived|ChannelNotFound|ChannelArchived */
    public function publish(PublishProduct $command): ListingView;

    /** @throws ProductNotFound|ListingNotFound */
    public function unpublish(UnpublishProduct $command): ListingView;

    /** @throws ListingNotFound */
    public function find(ProductId $product, ChannelId $channel): ListingView;

    /**
     * @return list<ListingView> ordered by channel id
     *
     * @throws ProductNotFound
     */
    public function ofProduct(ProductId $product): array;
}
