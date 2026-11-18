<?php

declare(strict_types=1);

namespace Commerz\Ports\Promotions\Exceptions;

use Commerz\Errors\CommerzException;
use Commerz\Errors\Enums\CommerzErrorCode;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;

/** A usage limit or budget is exhausted; `$limit` names which: `total`, `per_customer`, `coupon` or `budget`. */
#[Api(since: '0.1')]
final class UsageLimitReached extends CommerzException
{
    public function __construct(
        public readonly string $promotionId,
        public readonly ?string $couponCode,
        public readonly string $limit,
    ) {
        parent::__construct("Promotion '{$promotionId}' reached its {$limit} limit.");
    }

    public function code(): string
    {
        return 'promotions.usage_limit_reached';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::RateLimit;
    }

    public function context(): array
    {
        return ['promotion' => $this->promotionId, 'coupon' => $this->couponCode, 'limit' => $this->limit];
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: CommerzErrorCode::UsageLimitReached,
            category: ErrorCategory::RateLimit,
            retryable: false,
            details: $this->descriptorDetails(),
        );
    }
}
