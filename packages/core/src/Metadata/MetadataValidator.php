<?php

declare(strict_types=1);

namespace Commerz\Metadata;

use Commerz\Metadata\Exceptions\InvalidMetadata;
use Packman\Attributes\Api;

/**
 * Validates public and private metadata maps for root domain aggregates.
 */
#[Api(since: '0.1')]
final class MetadataValidator
{
    public const int MAX_KEY_LENGTH = 128;

    public const int MAX_KEYS = 256;

    public const int MAX_DEPTH = 8;

    public const int MAX_BYTES = 65536;

    private const string KEY_PATTERN = '/\A[a-zA-Z0-9_][a-zA-Z0-9_.\-:\/]{0,127}\z/';

    public static function validateKey(string $key, string $field = 'metadata'): string
    {
        if (strlen($key) < 1 || strlen($key) > self::MAX_KEY_LENGTH || preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw new InvalidMetadata(
                $field,
                "key '{$key}' must be 1..".self::MAX_KEY_LENGTH.' ASCII characters matching [a-zA-Z0-9_][a-zA-Z0-9_.-:/]*',
            );
        }

        return $key;
    }

    /**
     * @param  array<array-key, mixed>  $metadata
     * @return array<string, mixed>
     */
    public static function validateMap(array $metadata, string $field = 'metadata'): array
    {
        if (count($metadata) > self::MAX_KEYS) {
            throw new InvalidMetadata($field, 'cannot contain more than '.self::MAX_KEYS.' top-level keys');
        }

        $validated = [];

        foreach ($metadata as $key => $value) {
            if (! is_string($key)) {
                throw new InvalidMetadata($field, 'top-level metadata keys must be non-numeric strings');
            }

            self::validateKey($key, $field);
            $validated[$key] = self::validateValue($value, $field, $key, 1);
        }

        $encoded = json_encode((object) $validated, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);

        if (strlen($encoded) > self::MAX_BYTES) {
            throw new InvalidMetadata($field, 'serialized metadata exceeds '.self::MAX_BYTES.' bytes');
        }

        return $validated;
    }

    private static function validateValue(mixed $value, string $field, string $path, int $depth): mixed
    {
        if ($depth > self::MAX_DEPTH) {
            throw new InvalidMetadata($field, "value at '{$path}' exceeds maximum nesting depth of ".self::MAX_DEPTH);
        }

        if ($value === null || is_bool($value) || is_int($value)) {
            return $value;
        }

        if (is_string($value)) {
            if (! mb_check_encoding($value, 'UTF-8')) {
                throw new InvalidMetadata($field, "string value at '{$path}' is not valid UTF-8");
            }

            return $value;
        }

        if (is_float($value)) {
            if (! is_finite($value)) {
                throw new InvalidMetadata($field, "numeric value at '{$path}' must be finite");
            }

            return $value;
        }

        if (is_array($value)) {
            if (array_is_list($value)) {
                $list = [];
                foreach ($value as $index => $item) {
                    $list[] = self::validateValue($item, $field, "{$path}[{$index}]", $depth + 1);
                }

                return $list;
            }

            $nested = [];
            foreach ($value as $nestedKey => $nestedValue) {
                $stringKey = (string) $nestedKey;
                if ($stringKey === '' || ! mb_check_encoding($stringKey, 'UTF-8') || preg_match('/[\p{Cc}]/u', $stringKey) === 1) {
                    throw new InvalidMetadata($field, "nested key at '{$path}' must be a non-empty printable UTF-8 string");
                }

                $nested[$stringKey] = self::validateValue($nestedValue, $field, "{$path}.{$stringKey}", $depth + 1);
            }

            return $nested;
        }

        throw new InvalidMetadata($field, "unsupported value type '".get_debug_type($value)."' at '{$path}'");
    }
}
