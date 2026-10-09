<?php

declare(strict_types=1);

namespace Commerz\Inventory\Locations\Enums;

use Commerz\Attributes\Api;

/** What kind of place holds the stock. */
#[Api(since: '0.1')]
enum LocationKind: string
{
    case Warehouse = 'warehouse';
    case Store = 'store';
    case PickupPoint = 'pickup_point';
}
