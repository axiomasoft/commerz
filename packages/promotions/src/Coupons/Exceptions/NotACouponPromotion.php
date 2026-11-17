<?php

declare(strict_types=1);

namespace Commerz\Promotions\Coupons\Exceptions;

use Commerz\Errors\CommerzException;
use Commerz\Errors\ErrorCategory;
use Commerz\Promotions\Errors\Enums\PromotionsErrorCode;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory as PackmanErrorCategory;

/** Coupons belong to promotions of the `coupon` activator; this promotion is switched on another way. */
#[Api(since: '0.1')]
final class NotACouponPromotion extends CommerzException
{
    public function __construct(public readonly string $promotion, public readonly string $activator)
    {
        parent::__construct("Promotion '{$promotion}' is activated by '{$activator}', not by coupons.");
    }

    public function code(): string
    {
        return 'promotions.not_a_coupon_promotion';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return ['promotion' => $this->promotion, 'activator' => $this->activator];
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: PromotionsErrorCode::NotACouponPromotion,
            category: PackmanErrorCategory::Conflict,
            retryable: false,
            details: $this->context(),
        );
    }
}
