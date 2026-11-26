<?php

declare(strict_types=1);

namespace Commerz\CustomFields\Data;

use Commerz\CustomFields\Enums\CustomFieldFilterOperator;
use Commerz\Errors\InvalidCommand;
use Packman\Attributes\Api;

/**
 * Typed filter predicate against a filterable custom field in `cmz_custom_field_index`.
 */
#[Api(since: '0.1')]
final readonly class CustomFieldFilter
{
    public const int MAX_VALUES = 100;

    private const string IDENTIFIER_PATTERN = '/\A[a-z][a-z0-9_.\-]{0,63}\z/';

    /**
     * @param  list<mixed>  $values
     */
    private function __construct(
        public string $namespace,
        public string $key,
        public CustomFieldFilterOperator $operator,
        public array $values,
    ) {}

    public static function equals(string $namespace, string $key, mixed $value): self
    {
        self::assertIdentifiers($namespace, $key);

        if ($value === null) {
            throw new InvalidCommand('filters', "the filter value of '{$namespace}.{$key}' must not be null", 'custom_fields.invalid_filter');
        }

        return new self($namespace, $key, CustomFieldFilterOperator::Equals, [$value]);
    }

    /**
     * @param  array<array-key, mixed>  $values
     */
    public static function in(string $namespace, string $key, array $values): self
    {
        self::assertIdentifiers($namespace, $key);

        if ($values === [] || ! array_is_list($values) || count($values) > self::MAX_VALUES || in_array(null, $values, true)) {
            throw new InvalidCommand(
                'filters',
                "the filter values of '{$namespace}.{$key}' must be a list of 1 to ".self::MAX_VALUES.' non-null values',
                'custom_fields.invalid_filter',
            );
        }

        return new self($namespace, $key, CustomFieldFilterOperator::In, $values);
    }

    public static function between(string $namespace, string $key, mixed $min = null, mixed $max = null): self
    {
        self::assertIdentifiers($namespace, $key);

        if ($min === null && $max === null) {
            throw new InvalidCommand('filters', "the range filter of '{$namespace}.{$key}' needs at least one bound", 'custom_fields.invalid_filter');
        }

        return new self($namespace, $key, CustomFieldFilterOperator::Range, [$min, $max]);
    }

    public function qualifiedKey(): string
    {
        return "{$this->namespace}.{$this->key}";
    }

    private static function assertIdentifiers(string $namespace, string $key): void
    {
        if (preg_match(self::IDENTIFIER_PATTERN, $namespace) !== 1 || preg_match(self::IDENTIFIER_PATTERN, $key) !== 1) {
            throw new InvalidCommand('filters', "'{$namespace}.{$key}' is not a valid custom field identifier", 'custom_fields.invalid_filter');
        }
    }
}
