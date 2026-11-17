<?php

declare(strict_types=1);

namespace Commerz\Promotions\Coupons\Data;

use Commerz\Promotions\Coupons\Internal\CouponLimits;
use Packman\Attributes\Api;

/** Sets how many orders may use the coupon; null lifts the limit. The count of orders that already hold it is kept. */
#[Api(since: '0.1')]
final readonly class SetCouponLimit
{
    public function __construct(public CouponId $coupon, public ?int $usageLimit)
    {
        CouponLimits::assert('usageLimit', $usageLimit);
    }
}
