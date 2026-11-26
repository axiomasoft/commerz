<?php

declare(strict_types=1);

namespace Commerz\Sales\Carts\Data;

use Commerz\Ports\Delivery\Data\PickupPointDestination;
use Packman\Attributes\Api;

/** Sets or clears a cart's PUDO / BOPIS pickup destination without mutating the billing address. */
#[Api(since: '0.1')]
final readonly class SetCartPickupPoint
{
    public function __construct(
        public CartId $cart,
        public ?PickupPointDestination $pickupPoint,
    ) {}
}
