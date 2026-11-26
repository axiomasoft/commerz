<?php

declare(strict_types=1);

namespace Commerz\Sales\Exchanges\Enums;

use Packman\Attributes\Api;

#[Api(since: '0.1')]
enum ExchangeSettlement: string
{
    case Even = 'even';
    case PaymentDue = 'payment_due';
    case RefundDue = 'refund_due';
}
