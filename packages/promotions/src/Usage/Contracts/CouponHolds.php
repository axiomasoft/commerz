<?php

declare(strict_types=1);

namespace Commerz\Promotions\Usage\Contracts;

use Commerz\Errors\IdempotencyConflict;
use Commerz\Ports\Promotions\Exceptions\UsageLimitReached;
use Commerz\Promotions\Usage\Data\CouponHoldId;
use Commerz\Promotions\Usage\Data\CouponHoldView;
use Commerz\Promotions\Usage\Data\HoldCoupon;
use Commerz\Promotions\Usage\Data\RedeemCouponHold;
use Commerz\Promotions\Usage\Data\ReleaseCouponHold;
use Commerz\Promotions\Usage\Exceptions\CouponHoldDisabled;
use Commerz\Promotions\Usage\Exceptions\CouponHoldNotFound;
use Commerz\Promotions\Usage\Exceptions\CouponHoldUnavailable;
use Commerz\Promotions\Usage\Exceptions\PromotionUnavailable;
use DateTimeImmutable;
use Packman\Attributes\Api;

/**
 * Optional 2-phase coupon hold mode (`hold_on_checkout` with TTL: `held -> redeemed / released`) in addition to D13.
 */
#[Api(since: '0.1')]
interface CouponHolds
{
    /**
     * Reserves a coupon slot for a checkout session with a TTL. Idempotent per `(coupon, holdRef)` while held.
     *
     * @throws CouponHoldDisabled|PromotionUnavailable|UsageLimitReached|CouponHoldUnavailable
     */
    public function hold(HoldCoupon $command): CouponHoldView;

    /**
     * Converts an active hold (`held -> redeemed`) on order placement. Idempotent for the same `orderRef`.
     *
     * @throws CouponHoldNotFound|CouponHoldUnavailable|IdempotencyConflict|UsageLimitReached
     */
    public function redeem(RedeemCouponHold $command): CouponHoldView;

    /**
     * Releases an active hold (`held -> released`), returning the slot to the coupon. Idempotent if already released.
     *
     * @throws CouponHoldNotFound|CouponHoldUnavailable
     */
    public function release(ReleaseCouponHold $command): CouponHoldView;

    /** @throws CouponHoldNotFound */
    public function find(CouponHoldId $id): CouponHoldView;

    /**
     * Releases all held coupons whose TTL has expired as of `$asOf` (defaults to Clock::now()).
     *
     * @return int number of holds released
     */
    public function expireDue(?DateTimeImmutable $asOf = null): int;
}
