<?php

declare(strict_types=1);

namespace Commerz\Ports\Stock\Data;

use Commerz\Attributes\Api;
use Commerz\Identity\SellableRef;

#[Api(since: '0.1')]
final readonly class ReservedLine
{
    /** @param  list<StockAllocation>  $allocations  empty when stock is not tracked for the sellable */
    public function __construct(public SellableRef $sellable, public int $quantity, public array $allocations = []) {}
}
