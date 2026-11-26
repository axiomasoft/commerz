<?php

declare(strict_types=1);

namespace Commerz\Sales\Exchanges\Enums;

use Packman\Attributes\Api;

#[Api(since: '0.1')]
enum ClaimStatus: string
{
    case Created = 'created';
    case Fulfilled = 'fulfilled';
    case Cancelled = 'cancelled';
}
