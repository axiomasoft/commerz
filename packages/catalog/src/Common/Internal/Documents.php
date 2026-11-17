<?php

declare(strict_types=1);

namespace Commerz\Catalog\Common\Internal;

use JsonException;
use Packman\Attributes\Internal;
use RuntimeException;

/**
 * JSONB object documents. The top level is always an object, even when empty or keyed `0..n` (a code `'0'`):
 * the tables CHECK `jsonb_typeof = 'object'`.
 */
#[Internal]
final class Documents
{
    private const int FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;

    /** @param  array<array-key, mixed>  $document */
    public static function encode(array $document): string
    {
        return json_encode((object) $document, self::FLAGS);
    }

    /** @return array<array-key, mixed> a JSON object whose keys are all numeric comes back with integer keys */
    public static function decode(string $json): array
    {
        try {
            $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('Document is not valid JSON.', previous: $e);
        }

        if (! is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            throw new RuntimeException('Document is not a JSON object.');
        }

        return $decoded;
    }
}
