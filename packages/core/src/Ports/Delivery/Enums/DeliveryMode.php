<?php

declare(strict_types=1);

namespace Commerz\Ports\Delivery\Enums;

use Packman\Attributes\Api;

/** How goods reach the buyer: carrier shipping to an address, PUDO locker/point, or BOPIS in-store pickup. */
#[Api(since: '0.1')]
enum DeliveryMode: string
{
    case Shipping = 'shipping';
    case PickupPoint = 'pickup_point';
    case LocalPickup = 'local_pickup';

    public function requiresPickupPoint(): bool
    {
        return $this !== self::Shipping;
    }
}
