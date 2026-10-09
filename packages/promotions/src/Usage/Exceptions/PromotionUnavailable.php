<?php

declare(strict_types=1);

namespace Commerz\Promotions\Usage\Exceptions;

use Commerz\Attributes\Api;
use Commerz\Errors\CommerzException;
use Commerz\Errors\ErrorCategory;

/**
 * A promotion the quote applied can no longer be used when the order is placed: `$reason` is `promotion_not_found`,
 * `promotion_not_active`, `coupon_not_found` or `coupon_disabled`. The placement rolls back; quote again.
 */
#[Api(since: '0.1')]
final class PromotionUnavailable extends CommerzException
{
    public const string PROMOTION_NOT_FOUND = 'promotion_not_found';

    public const string PROMOTION_NOT_ACTIVE = 'promotion_not_active';

    public const string COUPON_NOT_FOUND = 'coupon_not_found';

    public const string COUPON_DISABLED = 'coupon_disabled';

    public function __construct(
        public readonly string $promotionId,
        public readonly ?string $couponCode,
        public readonly string $reason,
    ) {
        parent::__construct("Promotion '{$promotionId}'".($couponCode === null ? '' : " (coupon '{$couponCode}')")." is unavailable: {$reason}.");
    }

    public function code(): string
    {
        return 'promotions.promotion_unavailable';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return ['promotion' => $this->promotionId, 'coupon' => $this->couponCode, 'reason' => $this->reason];
    }
}
