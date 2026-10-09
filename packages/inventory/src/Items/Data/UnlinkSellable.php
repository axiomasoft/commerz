<?php

declare(strict_types=1);

namespace Commerz\Inventory\Items\Data;

use Commerz\Attributes\Api;
use Commerz\Identity\SellableRef;

/** Removes the link; a missing one is not an error. Reservations made earlier keep their own snapshot. */
#[Api(since: '0.1')]
final readonly class UnlinkSellable
{
    public function __construct(public SellableRef $sellable, public StockItemId $item) {}
}
