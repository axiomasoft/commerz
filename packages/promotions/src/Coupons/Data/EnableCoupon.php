<?php

declare(strict_types=1);

namespace Commerz\Promotions\Coupons\Data;

use Commerz\Attributes\Api;

#[Api(since: '0.1')]
final readonly class EnableCoupon
{
    public function __construct(public CouponId $coupon) {}
}
