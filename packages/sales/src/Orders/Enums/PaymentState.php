<?php

declare(strict_types=1);

namespace Commerz\Sales\Orders\Enums;

use Packman\Attributes\Api;

/** Derived from the amounts of the payments and refunds of the order, never set by a command. */
#[Api(since: '0.1')]
enum PaymentState: string
{
    case Unpaid = 'unpaid';
    case Authorized = 'authorized';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case PartiallyRefunded = 'partially_refunded';
    case Refunded = 'refunded';
}
