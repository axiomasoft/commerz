<?php

declare(strict_types=1);

namespace Commerz\Shipping\Packing\Data;

use Commerz\Money\Money;
use Packman\Attributes\Api;

/** Portion of a `PackableItem` placed into one `PackedParcel`. */
#[Api(since: '0.1')]
final readonly class PackedItem
{
    public function __construct(
        public string $itemId,
        public int $quantity,
        public int $physicalWeightGrams,
        public int $volumetricWeightGrams,
        public Money $value,
    ) {}
}
