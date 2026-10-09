<?php

declare(strict_types=1);

namespace Commerz\Inventory\Reservations\Events;

use Commerz\Attributes\Api;
use Commerz\Identity\SellableRef;
use Commerz\Ports\Stock\Data\StockAllocation;

/** Held units of a demand left stock as a sale (movement `sale`) in a committed operation. */
#[Api(since: '0.1')]
final readonly class StockConsumed
{
    /** @param  list<StockAllocation>  $allocations  sellable units taken per location */
    public function __construct(
        public string $demand,
        public SellableRef $sellable,
        public int $quantity,
        public string $reference,
        public array $allocations,
    ) {}
}
