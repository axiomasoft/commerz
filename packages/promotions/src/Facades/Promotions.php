<?php

declare(strict_types=1);

namespace Commerz\Promotions\Facades;

use Commerz\Promotions\Campaigns\Contracts\Campaigns;
use Commerz\Promotions\Coupons\Contracts\Coupons;
use Commerz\Promotions\Definitions\Contracts\PromotionDefinitions;
use Commerz\Promotions\Usage\Contracts\CouponHolds;
use Illuminate\Support\Facades\Facade;
use Packman\Attributes\Api;

/**
 * @method static PromotionDefinitions promotions()
 * @method static Coupons coupons()
 * @method static Campaigns campaigns()
 * @method static CouponHolds couponHolds()
 *
 * @see \Commerz\Promotions\Promotions
 */
#[Api(since: '0.1')]
final class Promotions extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Commerz\Promotions\Promotions::class;
    }
}
