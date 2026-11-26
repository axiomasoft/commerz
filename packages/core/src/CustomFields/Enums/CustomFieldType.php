<?php

declare(strict_types=1);

namespace Commerz\CustomFields\Enums;

use Packman\Attributes\Api;

/**
 * Canonical value types supported by `CustomFieldRegistry` and `cmz_custom_field_index`.
 */
#[Api(since: '0.1')]
enum CustomFieldType: string
{
    case String = 'string';
    case Integer = 'integer';
    case Decimal = 'decimal';
    case Boolean = 'boolean';
    case Date = 'date';
    case Enum = 'enum';
    case MultiEnum = 'multi_enum';
    case LocalizedString = 'localized_string';
    case EntityRef = 'entity_ref';
    case Json = 'json';

    public function supportsFiltering(): bool
    {
        return match ($this) {
            self::LocalizedString, self::Json => false,
            default => true,
        };
    }

    /**
     * @return list<CustomFieldFilterOperator>
     */
    public function operators(): array
    {
        return match ($this) {
            self::Integer, self::Decimal, self::Date => [
                CustomFieldFilterOperator::Equals,
                CustomFieldFilterOperator::In,
                CustomFieldFilterOperator::Range,
            ],
            self::Boolean => [
                CustomFieldFilterOperator::Equals,
            ],
            self::String, self::Enum, self::MultiEnum, self::EntityRef => [
                CustomFieldFilterOperator::Equals,
                CustomFieldFilterOperator::In,
            ],
            self::LocalizedString, self::Json => [],
        };
    }
}
