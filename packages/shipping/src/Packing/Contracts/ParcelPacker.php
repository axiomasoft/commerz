<?php

declare(strict_types=1);

namespace Commerz\Shipping\Packing\Contracts;

use Commerz\Shipping\Packing\Data\PackingRequest;
use Commerz\Shipping\Packing\Data\PackingResult;
use Commerz\Shipping\Packing\Exceptions\UnpackableItem;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * Splits shippable order/fulfillment items into 1..N `Parcel` boxes using physical and volumetric weights.
 */
#[Spi(kind: SpiKind::Implement, since: '0.1')]
interface ParcelPacker
{
    public const string KIND = 'parcel_packer';

    /** Contribution key, e.g. `weight_volume` or `acme.3d_bin`. */
    public function key(): string;

    /** @throws UnpackableItem */
    public function pack(PackingRequest $request): PackingResult;
}
