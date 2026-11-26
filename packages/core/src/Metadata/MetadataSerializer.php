<?php

declare(strict_types=1);

namespace Commerz\Metadata;

use Commerz\Metadata\Exceptions\InvalidMetadata;
use Commerz\Schema\Rows;
use JsonException;
use Packman\Attributes\Api;
use stdClass;

/**
 * Encodes and decodes JSONB metadata object maps while preserving PostgreSQL `jsonb_typeof = 'object'` invariants.
 */
#[Api(since: '0.1')]
final class MetadataSerializer
{
    private const int JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;

    /**
     * @param  array<array-key, mixed>  $metadata
     */
    public static function encodeMap(array $metadata, string $field = 'metadata'): string
    {
        $validated = MetadataValidator::validateMap($metadata, $field);

        return json_encode(self::toJsonObject($validated), self::JSON_FLAGS);
    }

    /**
     * @return array<string, mixed>
     */
    public static function decodeMap(?string $json, string $field = 'metadata'): array
    {
        if ($json === null || trim($json) === '') {
            return [];
        }

        try {
            $objectForm = json_decode($json, false, MetadataValidator::MAX_DEPTH + 4, JSON_THROW_ON_ERROR);
            $arrayForm = json_decode($json, true, MetadataValidator::MAX_DEPTH + 4, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidMetadata($field, 'malformed JSON document: '.$e->getMessage());
        }

        if (! $objectForm instanceof stdClass || ! is_array($arrayForm)) {
            throw new InvalidMetadata($field, 'root JSON value must be an object');
        }

        return MetadataValidator::validateMap($arrayForm, $field);
    }

    /**
     * @return array<string, string>
     */
    public static function encodeColumns(
        MetadataBag $bag,
        string $publicColumn = 'public_metadata',
        string $privateColumn = 'private_metadata',
    ): array {
        return [
            $publicColumn => $bag->toPublicJson(),
            $privateColumn => $bag->toPrivateJson(),
        ];
    }

    /**
     * @param  object|array<string, mixed>  $row
     */
    public static function decodeColumns(
        object|array $row,
        string $publicColumn = 'public_metadata',
        string $privateColumn = 'private_metadata',
    ): MetadataBag {
        if (is_array($row)) {
            $publicRaw = $row[$publicColumn] ?? null;
            $privateRaw = $row[$privateColumn] ?? null;

            return new MetadataBag(
                public: self::coerceColumn($publicRaw, $publicColumn),
                private: self::coerceColumn($privateRaw, $privateColumn),
            );
        }

        return MetadataBag::fromJson(
            publicJson: Rows::nullableString($row, $publicColumn),
            privateJson: Rows::nullableString($row, $privateColumn),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function coerceColumn(mixed $raw, string $field): array
    {
        if ($raw === null) {
            return [];
        }

        if (is_string($raw)) {
            return self::decodeMap($raw, $field);
        }

        if (is_array($raw)) {
            return MetadataValidator::validateMap($raw, $field);
        }

        throw new InvalidMetadata($field, 'expected JSON string, array, or null');
    }

    /**
     * @param  array<string, mixed>  $map
     */
    private static function toJsonObject(array $map): stdClass
    {
        $object = new stdClass;

        foreach ($map as $key => $value) {
            $object->{$key} = self::normalizeNestedForJson($value);
        }

        return $object;
    }

    private static function normalizeNestedForJson(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(self::normalizeNestedForJson(...), $value);
        }

        $object = new stdClass;
        foreach ($value as $k => $v) {
            $object->{(string) $k} = self::normalizeNestedForJson($v);
        }

        return $object;
    }
}
