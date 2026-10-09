<?php

declare(strict_types=1);

namespace Commerz\Promotions\Coupons\Data;

use Commerz\Attributes\Api;
use Commerz\Promotions\Coupons\Enums\CouponStatus;
use Commerz\Promotions\Definitions\Data\PromotionId;
use DateTimeImmutable;

#[Api(since: '0.1')]
final readonly class CouponView
{
    /** @param  int  $used  orders that hold the coupon: consumed usages in the log, not the limit counter */
    public function __construct(
        public CouponId $id,
        public PromotionId $promotion,
        public ?CouponBatchId $batch,
        public string $code,
        public CouponStatus $status,
        public ?int $usageLimit,
        public int $used,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}
}
