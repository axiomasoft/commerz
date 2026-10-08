<?php

declare(strict_types=1);

namespace Commerz\Ports\Sellables\Enums;

use Commerz\Attributes\Api;

#[Api(since: '0.1')]
enum SellableKind: string
{
    case Physical = 'physical';
    case Digital = 'digital';
    case Service = 'service';
}
