<?php

declare(strict_types=1);

namespace Commerz\Promotions\Coupons\Exceptions;

use Commerz\Attributes\Api;
use Commerz\Errors\NotFound;

#[Api(since: '0.1')]
final class CouponNotFound extends NotFound
{
    public function __construct(string $id)
    {
        parent::__construct('Coupon', $id, 'promotions.coupon_not_found');
    }
}
