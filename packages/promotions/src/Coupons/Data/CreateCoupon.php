<?php

declare(strict_types=1);

namespace Commerz\Promotions\Coupons\Data;

use Commerz\Promotions\Common\Data\PromotionCodes;
use Commerz\Promotions\Coupons\Internal\CouponLimits;
use Commerz\Promotions\Definitions\Data\PromotionId;
use Packman\Attributes\Api;

/** One coupon of a `coupon` promotion. The code is kept in its canonical form: trimmed and upper-cased. */
#[Api(since: '0.1')]
final readonly class CreateCoupon
{
    public string $code;

    public function __construct(
        public PromotionId $promotion,
        string $code,
        public ?int $usageLimit = null,
    ) {
        $this->code = PromotionCodes::couponCode('code', $code);
        CouponLimits::assert('usageLimit', $usageLimit);
    }
}
