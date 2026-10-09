<?php

declare(strict_types=1);

namespace Commerz\Inventory\Common\Data;

use Commerz\Attributes\Api;
use Commerz\Errors\InvalidCommand;

/** Field checks shared by the inventory commands. */
#[Api(since: '0.1')]
final class InventoryCodes
{
    /** Code of a stock location. */
    public static function code(string $field, string $value): string
    {
        if (preg_match('/\A[a-z0-9][a-z0-9_.\-]{0,63}\z/', $value) !== 1) {
            throw new InvalidCommand($field, "'{$value}' must match [a-z0-9][a-z0-9_.-]{0,63}", 'inventory.invalid_code');
        }

        return $value;
    }

    public static function sku(string $value): string
    {
        if (strlen($value) < 1 || strlen($value) > 191 || preg_match('/\A[^\s\p{Cc}]+\z/u', $value) !== 1) {
            throw new InvalidCommand('sku', 'must be 1 to 191 bytes without whitespace or control characters', 'inventory.invalid_sku');
        }

        return $value;
    }

    /** The document behind a stock movement, e.g. `receipt:2026-10-1`. */
    public static function reference(string $value, string $field = 'reference'): string
    {
        return self::printable($field, $value, 'inventory.invalid_reference');
    }

    /** Why a stock level changed, free text for the journal. */
    public static function reason(string $value, string $field = 'reason'): string
    {
        return self::printable($field, $value, 'inventory.invalid_reason');
    }

    private static function printable(string $field, string $value, string $code): string
    {
        if (strlen($value) > 191 || preg_match('/\A[^\p{Cc}\s][^\p{Cc}]*\z/u', $value) !== 1) {
            throw new InvalidCommand($field, 'must be 1..191 bytes of printable text', $code);
        }

        return $value;
    }
}
