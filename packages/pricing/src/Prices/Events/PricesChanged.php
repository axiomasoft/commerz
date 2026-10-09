<?php

declare(strict_types=1);

namespace Commerz\Pricing\Prices\Events;

use Commerz\Attributes\Api;
use Commerz\Pricing\PriceLists\Data\PriceListId;

/** Prices of the sellables were set, rescheduled or removed in a committed operation. */
#[Api(since: '0.1')]
final readonly class PricesChanged
{
    /** @param  list<string>  $sellables `type:id` of each sellable, without repeats */
    public function __construct(public PriceListId $priceList, public array $sellables) {}
}
