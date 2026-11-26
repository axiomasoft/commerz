<?php

declare(strict_types=1);

namespace Commerz\Promotions\Usage\Data;

use Packman\Attributes\Api;

/** Releases an active checkout coupon hold (`held -> released`), returning the slot to the coupon pool. */
#[Api(since: '0.1')]
final readonly class ReleaseCouponHold
{
    public function __construct(public CouponHoldId $hold) {}
}
