<?php

declare(strict_types=1);

namespace Commerz\Promotions\Usage\Exceptions;

use Commerz\Errors\CommerzException;
use Commerz\Promotions\Errors\Enums\PromotionsErrorCode;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;

/**
 * A checkout coupon hold cannot be transitioned to the requested state (`hold_expired`, `hold_released`,
 * `already_redeemed`, `coupon_disabled`, `promotion_not_active`).
 */
#[Api(since: '0.1')]
final class CouponHoldUnavailable extends CommerzException
{
    public const string HOLD_EXPIRED = 'hold_expired';

    public const string HOLD_RELEASED = 'hold_released';

    public const string ALREADY_REDEEMED = 'already_redeemed';

    public const string COUPON_DISABLED = 'coupon_disabled';

    public const string PROMOTION_NOT_ACTIVE = 'promotion_not_active';

    public function __construct(
        public readonly string $holdId,
        public readonly string $reason,
    ) {
        parent::__construct("Coupon hold '{$holdId}' is unavailable: {$reason}.");
    }

    public function code(): string
    {
        return 'promotions.coupon_hold_unavailable';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return ['hold' => $this->holdId, 'reason' => $this->reason];
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: PromotionsErrorCode::CouponHoldUnavailable,
            category: ErrorCategory::Conflict,
            retryable: false,
            details: $this->descriptorDetails(),
        );
    }
}
