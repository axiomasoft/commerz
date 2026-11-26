<?php

declare(strict_types=1);

namespace Commerz\Pricing\Prices\Enums;

use Packman\Attributes\Api;

#[Api(since: '0.1')]
enum PriceHistoryEvent: string
{
    case Set = 'set';
    case Rescheduled = 'rescheduled';
    case Removed = 'removed';
}
