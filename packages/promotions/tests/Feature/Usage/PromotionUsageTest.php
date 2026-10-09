<?php

declare(strict_types=1);

use Commerz\Identity\CustomerRef;
use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Ports\Promotions\Contracts\PromotionUsage;
use Commerz\Ports\Promotions\Data\ConsumePromotionUsage;
use Commerz\Ports\Promotions\Enums\RestoreReason;
use Commerz\Promotions\Common\Internal\PromotionsConfig;
use Commerz\Promotions\Coupons\Data\DisableCoupon;
use Commerz\Promotions\Definitions\Data\ArchivePromotion;
use Commerz\Promotions\Definitions\Data\PausePromotion;
use Commerz\Promotions\Definitions\Data\UsageLimits;
use Commerz\Promotions\Facades\Promotions;
use Commerz\Promotions\Rules\Enums\PromotionStage;
use Commerz\Promotions\Usage\Internal\DatabasePromotionUsage;
use Commerz\Testkit\Commerce;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Helpers.php';

require_once __DIR__.'/../Evaluation/Helpers.php';

uses(RefreshDatabase::class);

it('is the PromotionUsage port of the installation', function (): void {
    expect(app(PromotionUsage::class))->toBeInstanceOf(DatabasePromotionUsage::class);
});

it('writes nothing for an order without promotions', function (): void {
    usgConsume('order:1', [], 'customer:a');

    expect(DB::table('cmz_promotion_redemptions')->count())->toBe(0);
});

it('records the redemption and usages and counts only the limited counters (D27)', function (): void {
    $free = pdfPromotion('free');
    $limited = pdfPromotion('limited', ['limits' => new UsageLimits(total: 10, perCustomer: 2), 'budget' => Money::of('1000.00', 'RUB')]);
    $coupons = cpnPromotion('coupons');
    cpnCoupon($coupons, 'OPEN');
    cpnCoupon($coupons, 'ONCE', 5);
    $other = cpnPromotion('other');
    cpnCoupon($other, 'SECOND', 5);

    usgConsume('order:1', [usgApplied($limited, '150.00'), usgApplied($free, '10.00'), usgApplied($coupons, '20.00', ' once '), usgApplied($other, '5.00', 'SECOND')], 'customer:a');

    $redemption = DB::table('cmz_promotion_redemptions')->where('order_ref', 'order:1')->first();
    $usages = DB::table('cmz_promotion_usages')->orderBy('promotion_id')->get(['promotion_id', 'coupon_id', 'discount_minor', 'currency'])
        ->map(static fn (object $u): string => DB::table('cmz_promotions')->where('id', $u->promotion_id)->value('code')
            .'|'.($u->coupon_id === null ? '-' : DB::table('cmz_coupons')->where('id', $u->coupon_id)->value('code'))
            ."|{$u->discount_minor}|{$u->currency}")
        ->sort()->values()->all();

    expect([$redemption->state, $redemption->customer_ref, strlen($redemption->fingerprint), $redemption->restored_at])->toBe(['consumed', 'customer:a', 64, null])
        ->and($usages)->toBe(['coupons|ONCE|2000|RUB', 'free|-|1000|RUB', 'limited|-|15000|RUB', 'other|SECOND|500|RUB'])
        ->and(usgPromotionCounters($free))->toBe(['used' => 0, 'spent' => 0])
        ->and(usgPromotionCounters($limited))->toBe(['used' => 1, 'spent' => 15_000])
        ->and(usgPromotionCounters($coupons))->toBe(['used' => 0, 'spent' => 0])
        ->and(usgCouponUsed('ONCE'))->toBe(1)
        ->and(usgCouponUsed('OPEN'))->toBe(0)
        ->and(usgCouponUsed('SECOND'))->toBe(1)
        ->and(usgCustomers($limited))->toBe(['customer:a' => 1])
        ->and(usgCustomers($free))->toBe([]);
});

it('takes a repeat of the same order and payload as already done', function (): void {
    $limited = pdfPromotion('limited', ['limits' => new UsageLimits(total: 10, perCustomer: 1)]);
    $free = pdfPromotion('free');

    usgConsume('order:1', [usgApplied($limited), usgApplied($free)], 'customer:a');
    usgConsume('order:1', [usgApplied($free), usgApplied($limited)], 'customer:a'); // same payload, other order of the list

    expect(DB::table('cmz_promotion_redemptions')->count())->toBe(1)
        ->and(DB::table('cmz_promotion_usages')->count())->toBe(2)
        ->and(usgPromotionCounters($limited)['used'])->toBe(1)
        ->and(usgCustomers($limited))->toBe(['customer:a' => 1]);
});

it('refuses the same order with another payload', function (array $applied, ?string $customer): void {
    $a = pdfPromotion('a', ['limits' => new UsageLimits(total: 10)]);
    $b = pdfPromotion('b');
    usgConsume('order:1', [usgApplied($a)], 'customer:a');

    $applied = array_map(static fn (array $spec) => usgApplied($spec[0] === 'a' ? $a : $b, $spec[1]), $applied);

    expect(usgOutcome(fn () => usgConsume('order:1', $applied, $customer)))->toBe('common.idempotency_conflict')
        ->and(usgPromotionCounters($a)['used'])->toBe(1);
})->with([
    'another amount' => [[['a', '99.00']], 'customer:a'],
    'another promotion' => [[['a', '100.00'], ['b', '100.00']], 'customer:a'],
    'another customer' => [[['a', '100.00']], 'customer:b'],
]);

it('refuses a promotion that is no longer active or a coupon that is gone', function (Closure $change, string $outcome): void {
    $promotion = cpnPromotion('welcome', ['limits' => new UsageLimits(total: 10)]);
    cpnCoupon($promotion, 'HELLO', 3);
    $other = cpnPromotion('other');
    cpnCoupon($other, 'ELSEWHERE');

    $applied = $change($promotion);

    expect(usgOutcome(fn () => usgConsume('order:1', [$applied], 'customer:a')))->toBe($outcome)
        ->and(DB::table('cmz_promotion_redemptions')->count())->toBe(0)
        ->and(usgPromotionCounters($promotion)['used'])->toBe(0)
        ->and(usgCouponUsed('HELLO'))->toBe(0);
})->with([
    'paused' => [function ($promotion) {
        Promotions::promotions()->pause(new PausePromotion($promotion->id));

        return usgApplied($promotion, coupon: 'HELLO');
    }, 'unavailable:promotion_not_active'],
    'archived' => [function ($promotion) {
        Promotions::promotions()->archive(new ArchivePromotion($promotion->id));

        return usgApplied($promotion, coupon: 'HELLO');
    }, 'unavailable:promotion_not_active'],
    'coupon disabled' => [function ($promotion) {
        Promotions::coupons()->disable(new DisableCoupon(Promotions::coupons()->findByCode('HELLO')->id));

        return usgApplied($promotion, coupon: 'HELLO');
    }, 'unavailable:coupon_disabled'],
    'unknown coupon' => [fn ($promotion) => usgApplied($promotion, coupon: 'NOPE'), 'unavailable:coupon_not_found'],
    'not a coupon code' => [fn ($promotion) => usgApplied($promotion, coupon: 'no such code!'), 'unavailable:coupon_not_found'],
    'coupon of another promotion' => [fn ($promotion) => usgApplied($promotion, coupon: 'ELSEWHERE'), 'unavailable:coupon_not_found'],
    'unknown promotion' => [fn () => usgApplied('0199c0de-0000-7000-8000-000000000000'), 'unavailable:promotion_not_found'],
]);

it('refuses discounts in a currency the promotion is not in', function (): void {
    $promotion = pdfPromotion('rub', ['currency' => Currency::of('RUB'), 'limits' => new UsageLimits(total: 5)]);

    expect(usgOutcome(fn () => usgConsume('order:1', [usgApplied($promotion, '10.00', currency: 'USD')])))->toBe('promotions.currency_mismatch')
        ->and(usgPromotionCounters($promotion)['used'])->toBe(0);
});

it('refuses a malformed applied list', function (Closure $applied, string $field): void {
    $a = pdfPromotion('a');
    $b = pdfPromotion('b');

    expect(pdfFailure(fn () => usgConsume('order:1', $applied($a, $b))))->toBe("promotions.invalid_usage @ {$field}");
})->with([
    'not a uuid' => [fn ($a, $b) => [usgApplied('autumn')], 'applied.0.promotionId'],
    'listed twice' => [fn ($a, $b) => [usgApplied($a), usgApplied(strtoupper($a->id->value))], 'applied.1.promotionId'],
    'two currencies' => [fn ($a, $b) => [usgApplied($a), usgApplied($b, currency: 'USD')], 'applied.1.discount'],
]);

it('lets a guest not use a promotion limited per customer (D27)', function (): void {
    $promotion = pdfPromotion('once', ['limits' => new UsageLimits(perCustomer: 1)]);

    expect(usgOutcome(fn () => usgConsume('order:1', [usgApplied($promotion)])))->toBe('limit:per_customer')
        ->and(usgCustomers($promotion))->toBe([])
        ->and(DB::table('cmz_promotion_redemptions')->count())->toBe(0);
});

it('stops at each kind of limit and rolls the whole order back', function (array $with, ?int $couponLimit, string $first, string $second, string $limit): void {
    $promotion = cpnPromotion('limited', $with);
    cpnCoupon($promotion, 'CODE', $couponLimit);
    $free = pdfPromotion('free');
    $order = fn (string $ref, string $discount, string $customer) => usgConsume($ref, [usgApplied($free, '1.00'), usgApplied($promotion, $discount, 'CODE')], $customer);

    $order('order:1', $first, 'customer:a');
    $before = [usgPromotionCounters($promotion), usgCouponUsed('CODE'), usgCustomers($promotion)];

    expect(usgOutcome(fn () => $order('order:2', $second, 'customer:a')))->toBe("limit:{$limit}")
        ->and([usgPromotionCounters($promotion), usgCouponUsed('CODE'), usgCustomers($promotion)])->toBe($before)
        ->and(DB::table('cmz_promotion_redemptions')->pluck('order_ref')->all())->toBe(['order:1'])
        ->and(DB::table('cmz_promotion_usages')->count())->toBe(2);
})->with([
    'total' => [['limits' => new UsageLimits(total: 1)], null, '100.00', '100.00', 'total'],
    'per customer' => [['limits' => new UsageLimits(perCustomer: 1)], null, '100.00', '100.00', 'per_customer'],
    'coupon' => [[], 1, '100.00', '100.00', 'coupon'],
    'budget' => [['budget' => Money::of('150.00', 'RUB')], null, '100.00', '50.01', 'budget'],
    'total before budget' => [['limits' => new UsageLimits(total: 1), 'budget' => Money::of('100.00', 'RUB')], null, '100.00', '1.00', 'total'],
]);

it('spends a budget to the last minor unit', function (): void {
    $promotion = pdfPromotion('budget', ['budget' => Money::of('150.00', 'RUB')]);

    usgConsume('order:1', [usgApplied($promotion, '100.00')]);
    usgConsume('order:2', [usgApplied($promotion, '50.00')]);

    expect(usgPromotionCounters($promotion))->toBe(['used' => 0, 'spent' => 15_000])
        ->and(usgOutcome(fn () => usgConsume('order:3', [usgApplied($promotion, '0.01')])))->toBe('limit:budget')
        ->and(usgOutcome(fn () => usgConsume('order:4', [usgApplied($promotion, '0.00')])))->toBe('ok');
});

it('gives the usage of a cancelled or expired order back', function (RestoreReason $reason): void {
    $promotion = cpnPromotion('limited', ['limits' => new UsageLimits(total: 5, perCustomer: 2), 'budget' => Money::of('1000.00', 'RUB')]);
    cpnCoupon($promotion, 'CODE', 3);
    $free = pdfPromotion('free');
    usgConsume('order:1', [usgApplied($promotion, '100.00', 'CODE'), usgApplied($free)], 'customer:a');
    usgConsume('order:2', [usgApplied($promotion, '30.00', 'CODE')], 'customer:a');

    usgRestore('order:1', $reason);

    $redemption = DB::table('cmz_promotion_redemptions')->where('order_ref', 'order:1')->first();

    expect(usgPromotionCounters($promotion))->toBe(['used' => 1, 'spent' => 3_000])
        ->and(usgCouponUsed('CODE'))->toBe(1)
        ->and(usgCustomers($promotion))->toBe(['customer:a' => 1])
        ->and(usgPromotionCounters($free))->toBe(['used' => 0, 'spent' => 0])
        ->and([$redemption->state, $redemption->restore_reason, $redemption->restored_at !== null])->toBe(['restored', $reason->value, true])
        ->and(DB::table('cmz_promotion_usages')->count())->toBe(3);

    usgRestore('order:1', $reason); // a repeat changes nothing

    expect(usgPromotionCounters($promotion))->toBe(['used' => 1, 'spent' => 3_000])
        ->and(usgCouponUsed('CODE'))->toBe(1)
        ->and(usgCustomers($promotion))->toBe(['customer:a' => 1]);
})->with([RestoreReason::Cancelled, RestoreReason::Expired]);

it('keeps the usage of a refunded order unless the policy lists refunds', function (): void {
    $promotion = pdfPromotion('limited', ['limits' => new UsageLimits(total: 5)]);
    usgConsume('order:1', [usgApplied($promotion)], 'customer:a');

    usgRestore('order:1', RestoreReason::Refunded);

    expect(usgPromotionCounters($promotion)['used'])->toBe(1)
        ->and(DB::table('cmz_promotion_redemptions')->value('state'))->toBe('consumed');

    config()->set('commerz-promotions.restore_on', ['refunded']);
    app()->forgetInstance(PromotionsConfig::class);
    app()->forgetInstance(PromotionUsage::class);

    usgRestore('order:1', RestoreReason::Refunded);

    expect(usgPromotionCounters($promotion)['used'])->toBe(0)
        ->and(DB::table('cmz_promotion_redemptions')->value('restore_reason'))->toBe('refunded');
});

it('ignores the restore of an order that used no promotion', function (): void {
    $promotion = pdfPromotion('limited', ['limits' => new UsageLimits(total: 5)]);
    usgConsume('order:1', [usgApplied($promotion)]);

    usgRestore('order:unknown');

    expect(usgPromotionCounters($promotion)['used'])->toBe(1)
        ->and(DB::table('cmz_promotion_redemptions')->where('state', 'restored')->count())->toBe(0);
});

it('does not count a restored order again when it is consumed again', function (): void {
    $promotion = pdfPromotion('limited', ['limits' => new UsageLimits(total: 5)]);
    usgConsume('order:1', [usgApplied($promotion)]);
    usgRestore('order:1');

    usgConsume('order:1', [usgApplied($promotion)]);

    expect(usgPromotionCounters($promotion)['used'])->toBe(0)
        ->and(DB::table('cmz_promotion_redemptions')->value('state'))->toBe('restored');
});

it('consumes what a real quote applied (D25)', function (): void {
    Commerce::fake();
    $cart = evPromotion('cart', PromotionStage::Cart, [evLinePercent(1000)], ['limits' => new UsageLimits(total: 10, perCustomer: 1)]);
    $coupon = evPromotion('coupon', PromotionStage::Cart, [evOrderFixed('50.00')], ['activator' => 'coupon', 'budget' => Money::of('1000.00', 'RUB')]);
    cpnCoupon($coupon, 'SAVE50', 2);

    $applied = evRun(evInput(['2x500.00'], ['save50'], evContext('customer:a')))->appliedPromotions();
    app(DatabasePromotionUsage::class)->consume(new ConsumePromotionUsage('order:1', CustomerRef::fromString('customer:a'), $applied));

    expect(usgPromotionCounters($cart))->toBe(['used' => 1, 'spent' => 0])
        ->and(usgPromotionCounters($coupon))->toBe(['used' => 0, 'spent' => 5_000])
        ->and(usgCouponUsed('SAVE50'))->toBe(1)
        ->and(usgCustomers($cart))->toBe(['customer:a' => 1])
        ->and(DB::table('cmz_promotion_usages')->orderBy('discount_minor')->pluck('discount_minor')->map(static fn ($d): int => (int) $d)->all())->toBe([5_000, 10_000]);
});

it('refuses a budget overflow with a limit error and rolls back the redemption', function (): void {
    $promotion = pdfPromotion('huge', ['budget' => Money::ofMinor(PHP_INT_MAX, 'RUB')]);
    DB::table('cmz_promotions')->where('id', $promotion->id->value)->update(['spent_minor' => PHP_INT_MAX - 50]);

    expect(usgOutcome(fn () => usgConsume('order:overflow', [usgApplied($promotion, '1.00')])))->toBe('limit:budget')
        ->and(DB::table('cmz_promotion_redemptions')->where('order_ref', 'order:overflow')->exists())->toBeFalse()
        ->and(usgPromotionCounters($promotion)['spent'])->toBe(PHP_INT_MAX - 50);
});
