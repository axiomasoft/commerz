<?php

declare(strict_types=1);

namespace Commerz\Sales\OrderChanges\Enums;

use Packman\Attributes\Api;

/** Effective financial compensation strategy applied when confirming an order change. */
#[Api(since: '0.1')]
enum CompensationStrategy: string
{
    case None = 'none';
    case IncrementAuthorization = 'increment_authorization';
    case DeltaCharge = 'delta_charge';
    case PartialRefund = 'partial_refund';
    case WalletCredit = 'wallet_credit';
    case Deferred = 'deferred';
}
