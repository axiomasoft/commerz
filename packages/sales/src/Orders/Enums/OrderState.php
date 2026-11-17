<?php

declare(strict_types=1);

namespace Commerz\Sales\Orders\Enums;

use Packman\Attributes\Api;

/** `placed → completed | cancelled`; both ends are final. */
#[Api(since: '0.1')]
enum OrderState: string
{
    case Placed = 'placed';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}
