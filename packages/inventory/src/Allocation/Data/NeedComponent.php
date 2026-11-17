<?php

declare(strict_types=1);

namespace Commerz\Inventory\Allocation\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Inventory\Items\Data\StockItemId;
use Packman\Attributes\Api;

/** One stock item of a sellable unit; untracked items never appear. */
#[Api(since: '0.1')]
final readonly class NeedComponent
{
    /**
     * @param  int  $quantityPerUnit  stock units of the item in one sellable unit
     * @param  bool  $backorder  the item may be reserved beyond its free stock
     */
    public function __construct(
        public StockItemId $item,
        public int $quantityPerUnit,
        public bool $backorder = false,
    ) {
        if ($quantityPerUnit < 1) {
            throw new InvalidCommand('quantityPerUnit', 'must be at least 1', 'inventory.invalid_allocation');
        }
    }
}
