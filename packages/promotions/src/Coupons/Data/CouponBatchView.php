<?php

declare(strict_types=1);

namespace Commerz\Promotions\Coupons\Data;

use Commerz\Attributes\Api;
use Commerz\Promotions\Definitions\Data\PromotionId;
use DateTimeImmutable;

#[Api(since: '0.1')]
final readonly class CouponBatchView
{
    public function __construct(
        public CouponBatchId $id,
        public PromotionId $promotion,
        public string $pattern,
        public int $count,
        public ?int $usageLimit,
        public DateTimeImmutable $createdAt,
    ) {}
}
