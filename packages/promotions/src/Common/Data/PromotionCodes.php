<?php

declare(strict_types=1);

namespace Commerz\Promotions\Common\Data;

use Commerz\Errors\InvalidCommand;
use Packman\Attributes\Api;

/** Field checks shared by the promotions and coupons commands. */
#[Api(since: '0.1')]
final class PromotionCodes
{
    /** Administrative code of a promotion. */
    public static function code(string $field, string $value): string
    {
        if (preg_match('/\A[a-z0-9][a-z0-9_.\-]{0,63}\z/', $value) !== 1) {
            throw new InvalidCommand($field, "'{$value}' must match [a-z0-9][a-z0-9_.-]{0,63}", 'promotions.invalid_code');
        }

        return $value;
    }

    /** The canonical form of a coupon code: trimmed and upper-cased, `[A-Z0-9][A-Z0-9_-]{0,63}`. The only normalization there is. */
    public static function couponCode(string $field, string $value): string
    {
        $canonical = strtoupper(trim($value));

        if (preg_match('/\A[A-Z0-9][A-Z0-9_\-]{0,63}\z/', $canonical) !== 1) {
            throw new InvalidCommand($field, "'{$value}' must be 1 to 64 letters, digits, underscores or dashes and start with a letter or digit", 'promotions.invalid_coupon_code');
        }

        return $canonical;
    }

    /** Customer group a condition names, the same shape as `CommerceContext::$customerGroups`. */
    public static function group(string $field, string $value): string
    {
        if (preg_match('/\A[a-z0-9_.\-]{1,64}\z/', $value) !== 1) {
            throw new InvalidCommand($field, "'{$value}' must match [a-z0-9_.-]{1,64}", 'promotions.invalid_group');
        }

        return $value;
    }
}
