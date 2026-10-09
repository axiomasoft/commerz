<?php

declare(strict_types=1);

namespace Commerz\Shipping\Zones\Enums;

use Commerz\Attributes\Api;

#[Api(since: '0.1')]
enum ZoneStatus: string
{
    case Active = 'active';
    case Archived = 'archived';
}
