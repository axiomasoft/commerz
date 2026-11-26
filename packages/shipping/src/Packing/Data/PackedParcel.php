<?php

declare(strict_types=1);

namespace Commerz\Shipping\Packing\Data;

use Commerz\Ports\Delivery\Data\Parcel;
use Packman\Attributes\Api;

/** One packed box produced by `ParcelPacker`, carrying physical, volumetric, and chargeable weights in grams. */
#[Api(since: '0.1')]
final readonly class PackedParcel
{
    /** @param  non-empty-list<PackedItem>  $items */
    public function __construct(
        public int $sequence,
        public Parcel $parcel,
        public int $physicalWeightGrams,
        public int $volumetricWeightGrams,
        public int $chargeableWeightGrams,
        public array $items,
    ) {}
}
