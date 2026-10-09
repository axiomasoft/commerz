<?php

declare(strict_types=1);

namespace Commerz\Inventory\Transfers\Data;

use Commerz\Attributes\Api;
use Commerz\Errors\InvalidCommand;
use Commerz\Inventory\Items\Data\StockItemId;

/** Units of a stock item to move, in the item's base unit. */
#[Api(since: '0.1')]
final readonly class TransferLine
{
    public function __construct(public StockItemId $item, public int $quantity)
    {
        if ($quantity < 1) {
            throw new InvalidCommand('quantity', 'must be at least 1', 'inventory.invalid_quantity');
        }
    }
}
