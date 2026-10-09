<?php

declare(strict_types=1);

namespace Commerz\Pricing\Common\Internal;

use DateTimeImmutable;
use JsonException;
use RuntimeException;

/** Typed readers of a raw query-builder row; a missing or mistyped column is a programming error. */
final class Rows
{
    public static function string(object $row, string $column): string
    {
        $value = self::value($row, $column);

        return is_string($value) ? $value : throw new RuntimeException("Column '{$column}' is missing or not a string.");
    }

    public static function nullableString(object $row, string $column): ?string
    {
        $value = self::value($row, $column);

        return match (true) {
            $value === null => null,
            is_string($value) => $value,
            default => throw new RuntimeException("Column '{$column}' is not a string."),
        };
    }

    public static function int(object $row, string $column): int
    {
        $value = self::value($row, $column);

        return is_int($value) ? $value : throw new RuntimeException("Column '{$column}' is missing or not an integer.");
    }

    public static function nullableInt(object $row, string $column): ?int
    {
        $value = self::value($row, $column);

        return match (true) {
            $value === null => null,
            is_int($value) => $value,
            default => throw new RuntimeException("Column '{$column}' is not an integer."),
        };
    }

    public static function bool(object $row, string $column): bool
    {
        $value = self::value($row, $column);

        return is_bool($value) ? $value : throw new RuntimeException("Column '{$column}' is missing or not a boolean.");
    }

    /** @return array<array-key, mixed> */
    public static function json(object $row, string $column): array
    {
        try {
            $decoded = json_decode(self::string($row, $column), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException("Column '{$column}' is not valid JSON.", previous: $e);
        }

        return is_array($decoded) ? $decoded : throw new RuntimeException("Column '{$column}' is not a JSON object or list.");
    }

    public static function date(object $row, string $column): DateTimeImmutable
    {
        return new DateTimeImmutable(self::string($row, $column));
    }

    private static function value(object $row, string $column): mixed
    {
        $values = get_object_vars($row);

        return array_key_exists($column, $values) ? $values[$column] : throw new RuntimeException("Column '{$column}' is missing.");
    }
}
