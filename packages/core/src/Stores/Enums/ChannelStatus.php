<?php

declare(strict_types=1);

namespace Commerz\Stores\Enums;

use Commerz\Attributes\Api;

#[Api(since: '0.1')]
enum ChannelStatus: string
{
    case Active = 'active';
    case Archived = 'archived';
}
