<?php

declare(strict_types=1);

namespace Commerz\Pricing\PriceLists\Data;

use Commerz\Attributes\Api;

#[Api(since: '0.1')]
final readonly class RestorePriceList
{
    public function __construct(public PriceListId $priceList) {}
}
