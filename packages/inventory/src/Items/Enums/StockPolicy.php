<?php

declare(strict_types=1);

namespace Commerz\Inventory\Items\Enums;

use Packman\Attributes\Api;

/**
 * How reservations treat the item's stock: `tracked` reserves only free stock, `backorder` reserves beyond it
 * (consuming still needs physical stock), `untracked` is always available and keeps no levels.
 */
#[Api(since: '0.1')]
enum StockPolicy: string
{
    case Tracked = 'tracked';
    case Backorder = 'backorder';
    case Untracked = 'untracked';
}
