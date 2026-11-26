<?php

declare(strict_types=1);

namespace Commerz\Promotions\Usage\Data;

use Commerz\Identity\CustomerRef;
use Commerz\Promotions\Coupons\Data\CouponId;
use Commerz\Promotions\Definitions\Data\PromotionId;
use Commerz\Promotions\Usage\Enums\CouponHoldState;
use DateTimeImmutable;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class CouponHoldView
{
    public function __construct(
        public CouponHoldId $id,
        public CouponId $couponId,
        public PromotionId $promotionId,
        public string $couponCode,
        public string $holdRef,
        public ?CustomerRef $customer,
        public ?string $orderRef,
        public CouponHoldState $state,
        public DateTimeImmutable $heldAt,
        public DateTimeImmutable $expiresAt,
        public ?DateTimeImmutable $redeemedAt,
        public ?DateTimeImmutable $releasedAt,
    ) {}
}
