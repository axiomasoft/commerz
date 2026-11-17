<?php

declare(strict_types=1);

// Shared fixtures of the coupon tests.

use Commerz\Identity\Uuid7;
use Commerz\Promotions\Configuration\PromotionsOptions;
use Commerz\Promotions\Coupons\Contracts\Coupons;
use Commerz\Promotions\Coupons\Data\CouponId;
use Commerz\Promotions\Coupons\Data\CouponView;
use Commerz\Promotions\Coupons\Data\CreateCoupon;
use Commerz\Promotions\Definitions\Data\PromotionId;
use Commerz\Promotions\Definitions\Data\PromotionView;
use Commerz\Promotions\Facades\Promotions;
use Commerz\Promotions\Promotions as PromotionsRoot;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Definitions/Helpers.php';

/** @param  array<string, mixed>  $with */
function cpnPromotion(string $code = 'welcome', array $with = []): PromotionView
{
    return pdfPromotion($code, ['activator' => 'coupon'] + $with);
}

function cpnCoupon(PromotionView|PromotionId $promotion, string $code = 'HELLO', ?int $limit = null): CouponView
{
    $id = $promotion instanceof PromotionView ? $promotion->id : $promotion;

    return Promotions::coupons()->create(new CreateCoupon($id, $code, $limit));
}

/** A placed order that holds the coupon, written by SQL so no counter is touched. */
function cpnRedeem(CouponId $coupon, string $order, string $state = 'consumed'): void
{
    $redemption = Uuid7::generate();
    $promotion = DB::table('cmz_coupons')->where('id', $coupon->value)->value('promotion_id');

    DB::table('cmz_promotion_redemptions')->insert([
        'id' => $redemption,
        'order_ref' => $order,
        'customer_ref' => null,
        'fingerprint' => str_repeat('a', 64),
        'state' => $state,
        'restore_reason' => $state === 'restored' ? 'cancelled' : null,
        'consumed_at' => now(),
        'restored_at' => $state === 'restored' ? now() : null,
    ]);
    DB::table('cmz_promotion_usages')->insert([
        'redemption_id' => $redemption,
        'promotion_id' => $promotion,
        'coupon_id' => $coupon->value,
        'discount_minor' => 100,
        'currency' => 'RUB',
        'created_at' => now(),
    ]);
}

/** Makes the next coupon call read `commerz-promotions.*` again, after a test changed it. */
function cpnReloadConfig(): void
{
    app()->forgetInstance(PromotionsOptions::class);
    app()->forgetInstance(Coupons::class);
    app()->forgetInstance(PromotionsRoot::class);
    Promotions::clearResolvedInstances();
}
