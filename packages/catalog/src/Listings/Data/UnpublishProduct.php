<?php

declare(strict_types=1);

namespace Commerz\Catalog\Listings\Data;

use Commerz\Catalog\Products\Data\ProductId;
use Commerz\Stores\Data\ChannelId;
use Packman\Attributes\Api;

/** Takes a published product out of a channel; the listing stays, unpublished. Unpublishing twice changes nothing. */
#[Api(since: '0.1')]
final readonly class UnpublishProduct
{
    public function __construct(
        public ProductId $product,
        public ChannelId $channel,
    ) {}
}
