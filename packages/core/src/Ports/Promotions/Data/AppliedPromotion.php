<?php

declare(strict_types=1);

namespace Commerz\Ports\Promotions\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Money\Money;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class AppliedPromotion
{
    /** @param  Money  $discount  the positive amount the promotion took off the order (counts against its budget) */
    public function __construct(public string $promotionId, public ?string $couponCode, public Money $discount)
    {
        if ($promotionId === '') {
            throw new InvalidCommand('promotionId', 'must not be empty', 'promotions.invalid_usage');
        }

        if ($couponCode === '') {
            throw new InvalidCommand('couponCode', 'must be null or non-empty', 'promotions.invalid_usage');
        }

        if ($discount->isNegative()) {
            throw new InvalidCommand('discount', 'must not be negative', 'promotions.invalid_usage');
        }
    }
}
