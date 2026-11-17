<?php

declare(strict_types=1);

namespace Commerz\Catalog\Attributes\Enums;

use Packman\Attributes\Api;

#[Api(since: '0.1')]
enum FilterOperator: string
{
    case Equals = 'equals';
    case In = 'in';
    case Range = 'range';
}
