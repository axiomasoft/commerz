<?php

declare(strict_types=1);

namespace Commerz\Pricing\PriceLists\Events;

use Commerz\Pricing\PriceLists\Data\PriceListId;
use Packman\Attributes\Api;

/** A price list was created, changed, assigned to or removed from channels, archived or restored in a committed operation. */
#[Api(since: '0.1')]
final readonly class PriceListChanged
{
    public function __construct(public PriceListId $priceList) {}
}
