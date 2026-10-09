<?php

declare(strict_types=1);

namespace Commerz\Catalog\Common\Data;

use Commerz\Attributes\Api;
use Commerz\Errors\InvalidCommand;

/** Field checks shared by the catalog commands. */
#[Api(since: '0.1')]
final class CatalogCodes
{
    /** Code of an attribute, option, product type, category or choice. */
    public static function code(string $field, string $value): string
    {
        if (preg_match('/\A[a-z0-9][a-z0-9_.\-]{0,63}\z/', $value) !== 1) {
            throw new InvalidCommand($field, "'{$value}' must match [a-z0-9][a-z0-9_.-]{0,63}", 'catalog.invalid_code');
        }

        return $value;
    }

    public static function handle(string $value): string
    {
        if (preg_match('/\A[a-z0-9](?:[a-z0-9\-]{0,189}[a-z0-9])?\z/', $value) !== 1) {
            throw new InvalidCommand('handle', "'{$value}' must be 1 to 191 characters of a-z, 0-9 and '-', not starting or ending with '-'", 'catalog.invalid_handle');
        }

        return $value;
    }

    public static function sku(string $value): string
    {
        if (strlen($value) < 1 || strlen($value) > 191 || preg_match('/\A[^\s\p{Cc}]+\z/u', $value) !== 1) {
            throw new InvalidCommand('sku', 'must be 1 to 191 bytes without whitespace or control characters', 'catalog.invalid_sku');
        }

        return $value;
    }
}
