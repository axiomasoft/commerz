<?php

declare(strict_types=1);

namespace Commerz\CustomFields\Enums;

use Packman\Attributes\Api;

/**
 * Comparison operators supported by `cmz_custom_field_index` filter queries.
 */
#[Api(since: '0.1')]
enum CustomFieldFilterOperator: string
{
    case Equals = 'equals';
    case In = 'in';
    case Range = 'range';
}
