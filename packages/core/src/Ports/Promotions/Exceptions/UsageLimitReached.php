<?php

declare(strict_types=1);

namespace Commerz\Ports\Promotions\Exceptions;

use Commerz\Attributes\Api;
use Commerz\Errors\CommerzException;
use Commerz\Errors\ErrorCategory;

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
        return ErrorCategory::Limit;
    }

    public function context(): array
    {
        return ['promotion' => $this->promotionId, 'coupon' => $this->couponCode, 'limit' => $this->limit];
    }
}
