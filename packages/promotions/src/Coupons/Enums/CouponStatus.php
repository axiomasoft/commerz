<?php

declare(strict_types=1);

namespace Commerz\Promotions\Coupons\Enums;

use Packman\Attributes\Api;

/** A disabled coupon is refused at the quote and at the placement; enabling it brings it back. */
#[Api(since: '0.1')]
enum CouponStatus: string
{
    case Active = 'active';
    case Disabled = 'disabled';
}
