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
 * Thrown when 2-phase coupon hold operations are attempted while `commerz-promotions.hold_on_checkout` is false.
 */
#[Api(since: '0.1')]
final class CouponHoldDisabled extends CommerzException
{
    public function __construct()
    {
        parent::__construct('Two-phase coupon holds are disabled; enable commerz-promotions.hold_on_checkout.');
    }

    public function code(): string
    {
        return 'promotions.coupon_hold_disabled';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return [];
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: PromotionsErrorCode::CouponHoldDisabled,
            category: ErrorCategory::Conflict,
            retryable: false,
            details: $this->descriptorDetails(),
        );
    }
}
