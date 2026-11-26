<?php

declare(strict_types=1);

namespace Commerz\Promotions\Usage\Exceptions;

use Commerz\Errors\NotFound;
use Commerz\Promotions\Errors\Enums\PromotionsErrorCode;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;

#[Api(since: '0.1')]
final class CouponHoldNotFound extends NotFound
{
    public function __construct(string $id)
    {
        parent::__construct('CouponHold', $id, 'promotions.coupon_hold_not_found');
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: PromotionsErrorCode::CouponHoldNotFound,
            category: ErrorCategory::NotFound,
            retryable: false,
            details: $this->descriptorDetails(),
        );
    }
}
