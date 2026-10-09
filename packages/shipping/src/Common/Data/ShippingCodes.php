<?php

declare(strict_types=1);

namespace Commerz\Shipping\Common\Data;

use Commerz\Attributes\Api;
use Commerz\Errors\InvalidCommand;

/** Field checks shared by the zone and method commands. */
#[Api(since: '0.1')]
final class ShippingCodes
{
    /** Administrative code of a zone or a method. */
    public static function code(string $field, string $value): string
    {
        return self::match($field, $value);
    }

    /** Key of a carrier, e.g. `manual` or `cdek`: the same alphabet as a code. */
    public static function carrierKey(string $field, string $value): string
    {
        return self::match($field, $value);
    }

    private static function match(string $field, string $value): string
    {
        if (preg_match('/\A[a-z0-9][a-z0-9_.\-]{0,63}\z/', $value) !== 1) {
            throw new InvalidCommand($field, "'{$value}' must match [a-z0-9][a-z0-9_.-]{0,63}", 'shipping.invalid_code');
        }

        return $value;
    }
}
