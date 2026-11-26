<?php

declare(strict_types=1);

namespace Commerz\Sales\OrderChanges\Enums;

use Packman\Attributes\Api;

/** How a negative amountDelta (overpaid order) is handled on confirm(). */
#[Api(since: '0.1')]
enum NegativeDeltaStrategy: string
{
    case PartialRefund = 'partial_refund';
    case WalletCredit = 'wallet_credit';
    case Deferred = 'deferred';
}
