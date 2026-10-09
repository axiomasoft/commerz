<?php

declare(strict_types=1);

namespace Commerz\Inventory\Stock\Data;

use Commerz\Attributes\Api;
use Commerz\Errors\InvalidCommand;
use Commerz\Inventory\Items\Data\StockItemId;
use Commerz\Inventory\Locations\Data\LocationId;

/** Units of a level kept back from sale; they still count as on hand. */
#[Api(since: '0.1')]
final readonly class SetSafetyStock
{
    public function __construct(
        public StockItemId $item,
        public LocationId $location,
        public int $quantity,
    ) {
        if ($quantity < 0) {
            throw new InvalidCommand('quantity', 'must be zero or more', 'inventory.invalid_quantity');
        }
    }
}
