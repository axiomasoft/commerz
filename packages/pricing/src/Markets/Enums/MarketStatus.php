<?php

declare(strict_types=1);

namespace Commerz\Pricing\Markets\Enums;

use Packman\Attributes\Api;

#[Api(since: '0.1')]
enum MarketStatus: string
{
    case Active = 'active';
    case Archived = 'archived';
}
