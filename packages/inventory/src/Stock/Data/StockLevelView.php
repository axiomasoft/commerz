<?php

declare(strict_types=1);

namespace Commerz\Inventory\Stock\Data;

use Commerz\Attributes\Api;
use Commerz\Inventory\Items\Data\StockItemId;
use Commerz\Inventory\Locations\Data\LocationId;
use DateTimeImmutable;

/**
 * Stock of one item at one location. `available = onHand − reserved − safetyStock` and is negative while the level
 * is short. A level that was never touched reads as zeros with no `updatedAt`.
 */
#[Api(since: '0.1')]
final readonly class StockLevelView
{
    public int $available;

    public function __construct(
        public StockItemId $item,
        public string $sku,
        public LocationId $location,
        public string $locationCode,
        public int $onHand,
        public int $reserved,
        public int $safetyStock,
        public int $version,
        public ?DateTimeImmutable $updatedAt,
    ) {
        $free = $onHand - $reserved;
        // Saturates at PHP_INT_MIN: int64 quantities must never turn into a float.
        $this->available = $free < PHP_INT_MIN + $safetyStock ? PHP_INT_MIN : $free - $safetyStock;
    }
}
