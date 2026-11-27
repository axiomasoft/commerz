<?php

declare(strict_types=1);

function stanRow(mixed $row): stdClass
{
    if (! $row instanceof stdClass) {
        throw new RuntimeException('Expected a database row, got '.get_debug_type($row).'.');
    }

    return $row;
}

function stanInt(mixed $value): int
{
    if (is_int($value) || is_float($value) || is_string($value) || is_bool($value) || $value === null) {
        return (int) $value;
    }

    throw new RuntimeException('Expected an integer column, got '.get_debug_type($value).'.');
}

function stanString(mixed $value): string
{
    if (! is_string($value)) {
        throw new RuntimeException('Expected a string column, got '.get_debug_type($value).'.');
    }

    return $value;
}

function stanText(mixed $value): string
{
    if (is_string($value)) {
        return $value;
    }

    if (is_int($value) || is_float($value)) {
        return (string) $value;
    }

    throw new RuntimeException('Expected a textual column, got '.get_debug_type($value).'.');
}

/**
 * @return list<string>
 */
function stanStrings(mixed $values): array
{
    if (! is_array($values) && ! $values instanceof Traversable) {
        throw new RuntimeException('Expected a list of strings, got '.get_debug_type($values).'.');
    }

    $strings = [];

    foreach ($values as $value) {
        $strings[] = stanString($value);
    }

    return $strings;
}

/**
 * @return array<string, string>
 */
function stanStringMap(mixed $values): array
{
    if (! is_array($values) && ! $values instanceof Traversable) {
        throw new RuntimeException('Expected a string map, got '.get_debug_type($values).'.');
    }

    $map = [];

    foreach ($values as $key => $value) {
        if (! is_string($key)) {
            throw new RuntimeException('Expected a string map key, got '.get_debug_type($key).'.');
        }

        $map[$key] = stanString($value);
    }

    return $map;
}

/**
 * @return array<string, mixed>
 */
function stanStringKeyed(mixed $values): array
{
    if (! is_array($values)) {
        throw new RuntimeException('Expected an array, got '.get_debug_type($values).'.');
    }

    $typed = [];

    foreach ($values as $key => $value) {
        if (! is_string($key)) {
            throw new RuntimeException('Expected a string key, got '.get_debug_type($key).'.');
        }

        $typed[$key] = $value;
    }

    return $typed;
}

/**
 * @return array<string, int>
 */
function stanIntMap(mixed $values): array
{
    if (! is_array($values) && ! $values instanceof Traversable) {
        throw new RuntimeException('Expected an integer map, got '.get_debug_type($values).'.');
    }

    $map = [];

    foreach ($values as $key => $value) {
        if (! is_string($key)) {
            throw new RuntimeException('Expected a string map key, got '.get_debug_type($key).'.');
        }

        $map[$key] = stanInt($value);
    }

    return $map;
}
