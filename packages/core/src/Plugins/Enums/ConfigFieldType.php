<?php

declare(strict_types=1);

namespace Commerz\Plugins\Enums;

use Commerz\Plugins\Data\ConfigFieldSchema;
use Packman\Attributes\Api;

/**
 * Supported value types for plugin configuration fields declared via {@see ConfigFieldSchema}.
 */
#[Api(since: '0.1')]
enum ConfigFieldType: string
{
    case String = 'string';
    case Int = 'int';
    case Decimal = 'decimal';
    case Money = 'money';
    case Percent = 'percent';
    case Bool = 'bool';
    case Enum = 'enum';
    case LocalizedString = 'localized_string';
    case EntityRef = 'entity_ref';
}
