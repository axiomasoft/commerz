<?php

declare(strict_types=1);

namespace Commerz\Sales\Exchanges\Enums;

use Packman\Attributes\Api;

#[Api(since: '0.1')]
enum ExchangeStatus: string
{
    case Requested = 'requested';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}
