<?php

declare(strict_types=1);

// Shared fixtures of the promotion usage tests.

use Commerz\Errors\CommerzException;
use Commerz\Identity\CustomerRef;
use Commerz\Money\Money;
use Commerz\Ports\Promotions\Contracts\PromotionUsage;
use Commerz\Ports\Promotions\Data\AppliedPromotion;
use Commerz\Ports\Promotions\Data\ConsumePromotionUsage;
use Commerz\Ports\Promotions\Data\RestorePromotionUsage;
use Commerz\Ports\Promotions\Enums\RestoreReason;
use Commerz\Ports\Promotions\Exceptions\UsageLimitReached;
use Commerz\Promotions\Definitions\Data\PromotionView;
use Commerz\Promotions\Usage\Exceptions\PromotionUnavailable;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Coupons/Helpers.php';

function usgApplied(PromotionView|string $promotion, string $discount = '100.00', ?string $coupon = null, string $currency = 'RUB'): AppliedPromotion
{
    return new AppliedPromotion($promotion instanceof PromotionView ? $promotion->id->value : $promotion, $coupon, Money::of($discount, $currency));
}

/** @param  list<AppliedPromotion>  $applied */
function usgConsume(string $order, array $applied, ?string $customer = null): void
{
    app(PromotionUsage::class)->consume(new ConsumePromotionUsage($order, $customer === null ? null : CustomerRef::fromString($customer), $applied));
}

function usgRestore(string $order, RestoreReason $reason = RestoreReason::Cancelled): void
{
    app(PromotionUsage::class)->restore(new RestorePromotionUsage($order, $reason));
}

/**
 * How a call ends: `ok`, `limit:<limit>` for UsageLimitReached, `unavailable:<reason>` for PromotionUnavailable, or the
 * error code of another Commerz error.
 *
 * @param  Closure(): mixed  $call
 */
function usgOutcome(Closure $call): string
{
    try {
        $call();
    } catch (UsageLimitReached $e) {
        return "limit:{$e->limit}";
    } catch (PromotionUnavailable $e) {
        return "unavailable:{$e->reason}";
    } catch (CommerzException $e) {
        return $e->code();
    }

    return 'ok';
}

/** @return array{used: int, spent: int} */
function usgPromotionCounters(PromotionView|string $promotion): array
{
    $row = stanRow(DB::table('cmz_promotions')->where('id', $promotion instanceof PromotionView ? $promotion->id->value : $promotion)->first(['used', 'spent_minor']));

    return ['used' => stanInt($row->used), 'spent' => stanInt($row->spent_minor)];
}

function usgCouponUsed(string $code): int
{
    return stanInt(DB::table('cmz_coupons')->where('code', $code)->value('used'));
}

/** @return array<string, int> used by customer ref */
function usgCustomers(PromotionView|string $promotion): array
{
    return stanIntMap(DB::table('cmz_promotion_customer_usages')
        ->where('promotion_id', $promotion instanceof PromotionView ? $promotion->id->value : $promotion)
        ->orderBy('customer_ref')
        ->pluck('used', 'customer_ref'));
}

/** Usages whose redemption is still consumed, for the promotion. */
function usgConsumed(PromotionView|string $promotion): int
{
    return DB::table('cmz_promotion_usages as u')
        ->join('cmz_promotion_redemptions as r', 'r.id', '=', 'u.redemption_id')
        ->where('u.promotion_id', $promotion instanceof PromotionView ? $promotion->id->value : $promotion)
        ->where('r.state', 'consumed')
        ->count();
}
