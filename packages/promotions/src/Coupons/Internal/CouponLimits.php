<?php

declare(strict_types=1);

namespace Commerz\Promotions\Coupons\Internal;

use Commerz\Errors\InvalidCommand;

/** The one check of a coupon's usage limit shared by the coupon commands. */
final class CouponLimits
{
    private const int MAX = 2_147_483_647;

    public static function assert(string $field, ?int $limit): void
    {
        if ($limit !== null && ($limit < 1 || $limit > self::MAX)) {
            throw new InvalidCommand($field, 'must be null or from 1 to 2147483647', 'promotions.invalid_limits');
        }
    }
}
