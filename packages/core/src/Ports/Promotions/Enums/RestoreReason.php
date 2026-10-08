<?php

declare(strict_types=1);

namespace Commerz\Ports\Promotions\Enums;

use Commerz\Attributes\Api;

#[Api(since: '0.1')]
enum RestoreReason: string
{
    case Cancelled = 'cancelled';
    case Expired = 'expired';
    case Refunded = 'refunded';
}
