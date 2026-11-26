<?php

declare(strict_types=1);

namespace Commerz\Sales\OrderChanges\Enums;

use Packman\Attributes\Api;

/** Direction of financial reconciliation implied by an order change preview. */
#[Api(since: '0.1')]
enum CompensationDirection: string
{
    case None = 'none';
    case ChargeAdditional = 'charge_additional';
    case RefundOrCredit = 'refund_or_credit';
}
