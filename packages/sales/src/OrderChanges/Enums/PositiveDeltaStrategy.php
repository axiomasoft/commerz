<?php

declare(strict_types=1);

namespace Commerz\Sales\OrderChanges\Enums;

use Packman\Attributes\Api;

/** How a positive amountDelta (customer owes more) is handled on confirm(). */
#[Api(since: '0.1')]
enum PositiveDeltaStrategy: string
{
    case IncrementAuthorization = 'increment_authorization';
    case DeltaCharge = 'delta_charge';
    case Deferred = 'deferred';
}
