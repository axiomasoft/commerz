<?php

declare(strict_types=1);

namespace Commerz\Inventory\Stock\Events;

use Commerz\Inventory\Stock\Data\StockLevelView;
use Packman\Attributes\Api;

/**
 * A committed operation left the level short — `shortfall = reserved + safetyStock − onHand > 0` — and shorter than
 * before (D21: physical truth is recorded, never refused; the shortfall is reported instead).
 */
#[Api(since: '0.1')]
final readonly class StockShortfall
{
    public function __construct(
        public StockLevelView $level,
        public int $shortfall,
    ) {}
}
