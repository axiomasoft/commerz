<?php

declare(strict_types=1);

namespace Commerz\Catalog\Products\Data;

use Commerz\Attributes\Api;
use Commerz\Catalog\Attributes\Enums\FilterOperator;
use Commerz\Catalog\Common\Data\CatalogCodes;
use Commerz\Errors\InvalidCommand;

/**
 * A condition on a filterable attribute. Values are inputs of the attribute's type: they are normalized like a written
 * value, so '1.5' finds a stored '1.50'. Every filter of a query holds on one and the same active variant (CAT-03).
 */
#[Api(since: '0.1')]
final readonly class AttributeFilter
{
    /** Values one `in` filter takes. */
    public const int MAX_VALUES = 100;

    /**
     * @param  list<mixed>  $values  equals: [value]; in: the values; range: [min, max], a null bound is open
     */
    private function __construct(
        public string $attribute,
        public FilterOperator $operator,
        public array $values,
    ) {}

    /** The value equals; for a multi-choice attribute: the value list contains this choice. */
    public static function equals(string $attribute, mixed $value): self
    {
        if ($value === null) {
            throw new InvalidCommand('filters', "the value of '{$attribute}' must not be null", 'catalog.invalid_filter');
        }

        return new self(CatalogCodes::code('filters', $attribute), FilterOperator::Equals, [$value]);
    }

    /**
     * The value is one of these; for a multi-choice attribute: any of these choices is in the value list.
     *
     * @param  array<array-key, mixed>  $values  a list
     */
    public static function in(string $attribute, array $values): self
    {
        if ($values === [] || ! array_is_list($values) || count($values) > self::MAX_VALUES || in_array(null, $values, true)) {
            throw new InvalidCommand('filters', "the values of '{$attribute}' must be a list of 1 to ".self::MAX_VALUES.' non-null values', 'catalog.invalid_filter');
        }

        return new self(CatalogCodes::code('filters', $attribute), FilterOperator::In, $values);
    }

    /** min <= value <= max; a null bound leaves that side open. */
    public static function between(string $attribute, mixed $min = null, mixed $max = null): self
    {
        if ($min === null && $max === null) {
            throw new InvalidCommand('filters', "the range of '{$attribute}' needs at least one bound", 'catalog.invalid_filter');
        }

        return new self(CatalogCodes::code('filters', $attribute), FilterOperator::Range, [$min, $max]);
    }
}
