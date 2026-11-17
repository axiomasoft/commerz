<?php

declare(strict_types=1);

namespace Commerz\Sales\Carts\Enums;

use Packman\Attributes\Api;

#[Api(since: '0.1')]
enum CartState: string
{
    case Open = 'open';
    case Converted = 'converted';
}
