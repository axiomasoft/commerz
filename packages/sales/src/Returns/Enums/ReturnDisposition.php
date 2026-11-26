<?php

declare(strict_types=1);

namespace Commerz\Sales\Returns\Enums;

use Packman\Attributes\Api;

/**
 * Warehouse inspection outcome for returned physical goods (`INV-INV-002`).
 * `Restock` returns sellable units to `on_hand`; `DamagedWriteOff` journals a `write_off` stock movement
 * and NEVER increases sellable `on_hand`.
 */
#[Api(since: '0.1')]
enum ReturnDisposition: string
{
    case Restock = 'restock';
    case DamagedWriteOff = 'damaged_write_off';
}
