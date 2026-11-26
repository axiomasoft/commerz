<?php

declare(strict_types=1);

namespace Commerz\Sales\Exchanges\Enums;

use Packman\Attributes\Api;

#[Api(since: '0.1')]
enum ClaimKind: string
{
    case Replacement = 'replacement';
    case Reshipment = 'reshipment';
}
