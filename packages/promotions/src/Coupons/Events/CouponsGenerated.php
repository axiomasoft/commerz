<?php

declare(strict_types=1);

namespace Commerz\Promotions\Coupons\Events;

use Commerz\Promotions\Coupons\Data\CouponBatchId;
use Commerz\Promotions\Definitions\Data\PromotionId;
use Packman\Attributes\Api;

/** A batch of coupons was generated in a committed operation. */
#[Api(since: '0.1')]
final readonly class CouponsGenerated
{
    public function __construct(public PromotionId $promotion, public CouponBatchId $batch, public int $count) {}
}
