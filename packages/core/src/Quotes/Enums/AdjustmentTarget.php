<?php

declare(strict_types=1);

namespace Commerz\Quotes\Enums;

use Packman\Attributes\Api;

/** What an adjustment or tax applies to. */
#[Api(since: '0.1')]
enum AdjustmentTarget: string
{
    case Line = 'line';
    case Order = 'order';
    case Delivery = 'delivery';
}
