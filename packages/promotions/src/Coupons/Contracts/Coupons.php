<?php

declare(strict_types=1);

namespace Commerz\Promotions\Coupons\Contracts;

use Commerz\Errors\IdempotencyConflict;
use Commerz\Errors\InvalidCommand;
use Commerz\Pagination\Page;
use Commerz\Promotions\Common\Exceptions\DuplicateCode;
use Commerz\Promotions\Coupons\Data\CouponBatchView;
use Commerz\Promotions\Coupons\Data\CouponId;
use Commerz\Promotions\Coupons\Data\CouponView;
use Commerz\Promotions\Coupons\Data\CreateCoupon;
use Commerz\Promotions\Coupons\Data\DisableCoupon;
use Commerz\Promotions\Coupons\Data\EnableCoupon;
use Commerz\Promotions\Coupons\Data\GenerateCoupons;
use Commerz\Promotions\Coupons\Data\ListCoupons;
use Commerz\Promotions\Coupons\Data\SetCouponLimit;
use Commerz\Promotions\Coupons\Exceptions\CodeSpaceExhausted;
use Commerz\Promotions\Coupons\Exceptions\CouponNotFound;
use Commerz\Promotions\Coupons\Exceptions\NotACouponPromotion;
use Commerz\Promotions\Definitions\Exceptions\PromotionArchived;
use Commerz\Promotions\Definitions\Exceptions\PromotionNotFound;
use Packman\Attributes\Api;

/**
 * Coupons of the `coupon` promotions. A code has one form, trimmed and upper-cased, whether it is written, looked up or
 * presented with a quote.
 */
#[Api(since: '0.1')]
interface Coupons
{
    /**
     * @throws PromotionNotFound|PromotionArchived|NotACouponPromotion
     * @throws DuplicateCode
     */
    public function create(CreateCoupon $command): CouponView;

    /**
     * Creates exactly `count` new codes, or nothing. With an idempotency key a retry returns the batch of the first call.
     *
     * @throws PromotionNotFound|PromotionArchived|NotACouponPromotion
     * @throws InvalidCommand promotions.invalid_pattern, promotions.pattern_too_small, promotions.invalid_count
     * @throws CodeSpaceExhausted
     * @throws IdempotencyConflict the key was used with other arguments
     */
    public function generate(GenerateCoupons $command): CouponBatchView;

    /** Disabling a disabled coupon changes nothing. @throws CouponNotFound */
    public function disable(DisableCoupon $command): CouponView;

    /** Enabling an active coupon changes nothing. @throws CouponNotFound */
    public function enable(EnableCoupon $command): CouponView;

    /** @throws CouponNotFound */
    public function setLimit(SetCouponLimit $command): CouponView;

    /** @throws CouponNotFound */
    public function find(CouponId $id): CouponView;

    /** The code is normalized first. @throws CouponNotFound */
    public function findByCode(string $code): CouponView;

    /** @return Page<CouponView> */
    public function list(ListCoupons $query): Page;
}
