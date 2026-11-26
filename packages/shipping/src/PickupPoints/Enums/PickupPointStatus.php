<?php

declare(strict_types=1);

namespace Commerz\Shipping\PickupPoints\Enums;

use Packman\Attributes\Api;

#[Api(since: '0.1')]
enum PickupPointStatus: string
{
    case Active = 'active';
    case Disabled = 'disabled';
    case Archived = 'archived';
}
