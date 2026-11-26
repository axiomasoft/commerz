<?php

declare(strict_types=1);

namespace Commerz\Inventory\Stock\Enums;

use Packman\Attributes\Api;

/** Why the on-hand quantity of a level changed; one journal row per change. */
#[Api(since: '0.1')]
enum MovementKind: string
{
    case Receipt = 'receipt';
    case Adjustment = 'adjustment';
    case Count = 'count';
    case Sale = 'sale';
    case TransferOut = 'transfer_out';
    case TransferIn = 'transfer_in';
    case Return = 'return';
    case WriteOff = 'write_off';
}
