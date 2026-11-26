<?php

declare(strict_types=1);

namespace Commerz\Promotions\Usage\Data;

use Commerz\Errors\InvalidCommand;
use Packman\Attributes\Api;

/** Converts an active checkout coupon hold (`held -> redeemed`) for the placed order. */
#[Api(since: '0.1')]
final readonly class RedeemCouponHold
{
    public string $orderRef;

    public function __construct(
        public CouponHoldId $hold,
        string $orderRef,
    ) {
        $trimmed = trim($orderRef);

        if ($trimmed === '' || strlen($trimmed) > 191) {
            throw new InvalidCommand('orderRef', 'must be 1 to 191 bytes', 'promotions.invalid_usage');
        }

        $this->orderRef = $trimmed;
    }
}
