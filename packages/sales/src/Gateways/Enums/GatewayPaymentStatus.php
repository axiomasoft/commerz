<?php

declare(strict_types=1);

namespace Commerz\Sales\Gateways\Enums;

use Commerz\Attributes\Api;

/** What a gateway reports about a payment attempt. A final refusal is `PaymentDeclined`, not a status of an accepted attempt. */
#[Api(since: '0.1')]
enum GatewayPaymentStatus: string
{
    case Pending = 'pending';
    case RequiresAction = 'requires_action';
    case Authorized = 'authorized';
    case Captured = 'captured';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
