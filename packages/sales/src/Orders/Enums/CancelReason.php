<?php

declare(strict_types=1);

namespace Commerz\Sales\Orders\Enums;

use Commerz\Attributes\Api;

#[Api(since: '0.1')]
enum CancelReason: string
{
    case Customer = 'customer';
    case Operator = 'operator';
    case Expired = 'expired';
    case OutOfStock = 'out_of_stock';
    case Fraud = 'fraud';
    case Other = 'other';
}
