<?php

declare(strict_types=1);

namespace Commerz\Pricing\Common\Data;

use Commerz\Errors\InvalidCommand;
use Packman\Attributes\Api;

/** Field checks shared by the pricing commands. */
#[Api(since: '0.1')]
final class PricingCodes
{
    /** Code of a price list, tax class or tax zone. */
    public static function code(string $field, string $value): string
    {
        if (preg_match('/\A[a-z0-9][a-z0-9_.\-]{0,63}\z/', $value) !== 1) {
            throw new InvalidCommand($field, "'{$value}' must match [a-z0-9][a-z0-9_.-]{0,63}", 'pricing.invalid_code');
        }

        return $value;
    }

    /** Customer group a price is limited to, the same shape as `CommerceContext::$customerGroups`. */
    public static function group(string $field, string $value): string
    {
        if (preg_match('/\A[a-z0-9_.\-]{1,64}\z/', $value) !== 1) {
            throw new InvalidCommand($field, "'{$value}' must match [a-z0-9_.-]{1,64}", 'pricing.invalid_group');
        }

        return $value;
    }

    /** Identifier of a tax on a rate and on a tax line, e.g. `vat`. */
    public static function taxCode(string $field, string $value): string
    {
        if (preg_match('/\A[a-z0-9_]{1,32}\z/', $value) !== 1) {
            throw new InvalidCommand($field, "'{$value}' must match [a-z0-9_]{1,32}", 'pricing.invalid_tax_code');
        }

        return $value;
    }
}
