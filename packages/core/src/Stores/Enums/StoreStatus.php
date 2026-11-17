<?php

declare(strict_types=1);

namespace Commerz\Stores\Enums;

use Packman\Attributes\Api;

#[Api(since: '0.1')]
enum StoreStatus: string
{
    case Active = 'active';
    case Archived = 'archived';
}
