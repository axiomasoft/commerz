<?php

declare(strict_types=1);

namespace Commerz\Promotions\Coupons\Data;

use Commerz\Identity\EntityId;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class CouponId extends EntityId {}
