<?php

declare(strict_types=1);

namespace Commerz\Coupons\Exceptions;

use Commerz\Attributes\Api;
use DomainException;

#[Api(since: '1.0')]
final class InvalidCouponCode extends DomainException
{
    public static function format(string $input): self
    {
        return new self('Coupon code must contain 3..64 latin letters or digits after normalization, got ['.mb_substr($input, 0, 70).'].');
    }
}
