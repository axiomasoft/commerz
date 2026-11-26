<?php

declare(strict_types=1);

namespace Commerz\Promotions\Usage\Enums;

use Packman\Attributes\Api;

/** Lifecycle state of a 2-phase checkout coupon hold (`held -> redeemed | released`). */
#[Api(since: '0.1')]
enum CouponHoldState: string
{
    case Held = 'held';
    case Redeemed = 'redeemed';
    case Released = 'released';
}
