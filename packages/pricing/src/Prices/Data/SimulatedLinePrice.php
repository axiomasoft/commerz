<?php

declare(strict_types=1);

namespace Commerz\Pricing\Prices\Data;

use Commerz\Identity\SellableRef;
use Commerz\Money\Money;
use Packman\Attributes\Api;

/** Per-line comparison between live pricing and staged pricing after active promotions have run. */
#[Api(since: '0.1')]
final readonly class SimulatedLinePrice
{
    public function __construct(
        public string $lineId,
        public SellableRef $sellable,
        public int $quantity,
        public ?Money $liveUnitPrice,
        public Money $stagedUnitPrice,
        public ?Money $stagedCompareAt,
        public Money $stagedSubtotal,
        public Money $promotionDiscount,
        public Money $effectiveNetTotal,
        public Money $settledTotal,
        public bool $stacksWithPromotions,
    ) {}
}
