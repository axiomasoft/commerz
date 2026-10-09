<?php

declare(strict_types=1);

// Golden examples G1–G21 of knowledge/promotions-design#golden, through the real quote pipeline (G22 is in ExplainTest).
// G1–G12 run with the promotions created in both orders: the result must not depend on it (PRM-04).

use Commerz\Money\Money;
use Commerz\Ports\Promotions\Data\AppliedPromotion;
use Commerz\Promotions\Coupons\Data\DisableCoupon;
use Commerz\Promotions\Definitions\Data\UsageLimits;
use Commerz\Promotions\Definitions\Enums\Stacking;
use Commerz\Promotions\Facades\Promotions;
use Commerz\Promotions\Rules\Data\RuleSpec;
use Commerz\Promotions\Rules\Enums\PromotionStage;
use Commerz\Quotes\Enums\AdjustmentTarget;
use Commerz\Testkit\Commerce;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Helpers.php';

uses(RefreshDatabase::class, EvEnvironment::class);

beforeEach(function (): void {
    Commerce::fake();
});

dataset('creation order', ['as listed' => [false], 'reversed' => [true]]);

it('stacks percentages on the reduced price, higher priority first (G1)', function (bool $reverse): void {
    evCreate([
        fn () => evPromotion('a', PromotionStage::Cart, [evLinePercent(1000)], ['priority' => 200]),
        fn () => evPromotion('b', PromotionStage::Cart, [evLinePercent(500)], ['priority' => 100]),
    ], $reverse);

    $result = evRun(evInput(['1000.00']));

    expect(evDiscounts($result))->toBe(['line:1 -100.00 a', 'line:1 -45.00 b'])
        ->and($result->totals->discountTotal->toDecimal())->toBe('145.00');
})->with('creation order');

it('breaks a priority tie by the promotion code (G2)', function (bool $reverse): void {
    evCreate([
        fn () => evPromotion('b-promo', PromotionStage::Cart, [evLinePercent(500)]),
        fn () => evPromotion('a-promo', PromotionStage::Cart, [evLinePercent(1000)]),
    ], $reverse);

    expect(evDiscounts(evRun(evInput(['1000.00']))))->toBe(['line:1 -100.00 a-promo', 'line:1 -45.00 b-promo']);
})->with('creation order');

it('applies an exclusive promotion that comes first and stops the rest (G3)', function (bool $reverse): void {
    evCreate([
        fn () => evPromotion('e', PromotionStage::Cart, [evLinePercent(1000)], ['priority' => 300, 'stacking' => Stacking::Exclusive]),
        fn () => evPromotion('s', PromotionStage::Cart, [evLinePercent(500)], ['priority' => 100]),
    ], $reverse);

    $input = evInput(['1000.00']);

    expect(evDiscounts(evRun($input)))->toBe(['line:1 -100.00 e'])
        ->and(evVerdicts($input))->toBe(['e|applied|100.00', 's|skipped|stopped_by_promotion']);
})->with('creation order');

it('skips an exclusive promotion after one has applied (G4)', function (bool $reverse): void {
    evCreate([
        fn () => evPromotion('s', PromotionStage::Cart, [evLinePercent(500)], ['priority' => 300]),
        fn () => evPromotion('e', PromotionStage::Cart, [evLinePercent(1000)], ['priority' => 100, 'stacking' => Stacking::Exclusive]),
    ], $reverse);

    $input = evInput(['1000.00']);

    expect(evDiscounts(evRun($input)))->toBe(['line:1 -50.00 s'])
        ->and(evVerdicts($input))->toBe(['s|applied|50.00', 'e|skipped|not_combinable']);
})->with('creation order');

it('stops the promotions after one with stopFurther (G5)', function (bool $reverse): void {
    evCreate([
        fn () => evPromotion('a', PromotionStage::Cart, [evLinePercent(1000)], ['priority' => 300, 'stopFurther' => true]),
        fn () => evPromotion('b', PromotionStage::Cart, [evLinePercent(500)], ['priority' => 100]),
    ], $reverse);

    $input = evInput(['1000.00']);

    expect(evDiscounts(evRun($input)))->toBe(['line:1 -100.00 a'])
        ->and(evVerdicts($input))->toBe(['a|applied|100.00', 'b|skipped|stopped_by_promotion']);
})->with('creation order');

it('runs the cart stage on what the catalog stage left (G6)', function (bool $reverse): void {
    evCreate([
        fn () => evPromotion('catalog', PromotionStage::Catalog, [evLinePercent(1000)]),
        fn () => evPromotion('cart', PromotionStage::Cart, [pdfPercent(1000)]),
    ], $reverse);

    $result = evRun(evInput(['1000.00']));

    expect(evDiscounts($result))->toBe(['line:1 -100.00 catalog', 'order -90.00 cart'])
        ->and($result->total->toDecimal())->toBe('810.00');
})->with('creation order');

it('clamps an order discount to the order and spreads it over the lines (G7)', function (bool $reverse): void {
    evCreate([fn () => evPromotion('big', PromotionStage::Cart, [evOrderFixed('500.00')])], $reverse);

    $result = evRun(evInput(['100.00', '200.00']));
    $shares = array_map(
        static fn ($line): array => array_map(static fn ($a): string => $a->amount->toDecimal(), array_values(array_filter($line->adjustments, static fn ($a): bool => $a->target === AdjustmentTarget::Order))),
        $result->lines,
    );

    expect(evDiscounts($result))->toBe(['order -300.00 big'])
        ->and($result->total->toDecimal())->toBe('0.00')
        ->and($shares)->toBe([['-100.00'], ['-200.00']]);
})->with('creation order');

it('takes a fixed amount off each unit (G8)', function (bool $reverse): void {
    evCreate([fn () => evPromotion('fixed', PromotionStage::Cart, [evLineFixed('30.00')])], $reverse);

    expect(evDiscounts(evRun(evInput(['3x100.00']))))->toBe(['line:1 -90.00 fixed']);
})->with('creation order');

it('gives the cheapest units free with buy 2 get 1 (G9)', function (bool $reverse): void {
    evCreate([fn () => evPromotion('bxgy', PromotionStage::Cart, [evBuyXGetY()])], $reverse);

    expect(evDiscounts(evRun(evInput(['A' => '3x100.00', 'B' => '1x50.00', 'C' => '2x80.00']))))
        ->toBe(['line:B -50.00 bxgy', 'line:C -80.00 bxgy']);
})->with('creation order');

it('caps buy 2 get 1 at maxApplications (G10)', function (bool $reverse): void {
    evCreate([fn () => evPromotion('bxgy', PromotionStage::Cart, [evBuyXGetY(['maxApplications' => 1])])], $reverse);

    expect(evDiscounts(evRun(evInput(['A' => '3x100.00', 'B' => '1x50.00', 'C' => '2x80.00']))))
        ->toBe(['line:B -50.00 bxgy']);
})->with('creation order');

it('takes a share of the chosen units with buy 2 get 1 at 50% (G11)', function (bool $reverse): void {
    evCreate([fn () => evPromotion('bxgy', PromotionStage::Cart, [evBuyXGetY(['rateBp' => 5000])])], $reverse);

    expect(evDiscounts(evRun(evInput(['A' => '3x100.00', 'B' => '1x50.00', 'C' => '2x80.00']))))
        ->toBe(['line:B -25.00 bxgy', 'line:C -40.00 bxgy']);
})->with('creation order');

it('prices units of a discounted line exactly for buy 2 get 1 (G12)', function (bool $reverse): void {
    evCreate([
        fn () => evPromotion('cent', PromotionStage::Catalog, [evLineFixed('0.01', 'line')]),
        fn () => evPromotion('bxgy', PromotionStage::Cart, [evBuyXGetY()]),
    ], $reverse);

    expect(evDiscounts(evRun(evInput(['A' => '3x100.00']))))->toBe(['line:A -0.01 cent', 'line:A -99.99 bxgy']);
})->with('creation order');

it('rejects a coupon promotion whose minimum is not reached after the catalog discount (G13)', function (): void {
    evPromotion('catalog', PromotionStage::Catalog, [evLinePercent(1000)]);
    $coupon = cpnPromotion('autumn', ['conditions' => [pdfMinimum('3000.00')], 'effects' => [pdfPercent(500)]]);
    cpnCoupon($coupon, 'AUTUMN');

    $result = evRun(evInput(['3000.00'], ['AUTUMN']));

    expect(evDiscounts($result))->toBe(['line:1 -300.00 catalog'])
        ->and(evRejections($result))->toBe(['promotions.cart|minimum_not_reached|AUTUMN'])
        ->and($result->rejections[0]->promotionId)->toBe($coupon->id->value);
});

it('discounts only the lines of a category (G14)', function (): void {
    evPromotion('shoes', PromotionStage::Cart, [evLinePercent(2000)], ['conditions' => [new RuleSpec('line.category_in', ['categories' => ['shoes']])]]);

    expect(evDiscounts(evRun(evInput([['1000.00', 'categories' => ['shoes']], ['500.00', 'categories' => ['hats']]]))))
        ->toBe(['line:1 -200.00 shoes']);
});

it('makes the delivery free by a code, and rejects the code without a delivery (G15)', function (): void {
    $free = cpnPromotion('free-ship', ['stage' => PromotionStage::Delivery, 'effects' => [evDeliveryPercent(10000)]]);
    cpnCoupon($free, 'FREESHIP');

    $shipped = evRun(evInput(['1000.00'], ['FREESHIP'], delivery: '300.00'));
    $collected = evRun(evInput(['1000.00'], ['FREESHIP']));

    expect(evDiscounts($shipped))->toBe(['delivery -300.00 free-ship FREESHIP'])
        ->and($shipped->total->toDecimal())->toBe('1000.00')
        ->and(evDiscounts($collected))->toBe([])
        ->and(evRejections($collected))->toBe(['promotions.delivery|no_delivery|FREESHIP']);
});

it('rejects a promotion whose budget the discount would overrun (G16)', function (): void {
    $promotion = evPromotion('budget', PromotionStage::Cart, [pdfPercent(1000)], ['budget' => Money::of('100.00', 'RUB')]);
    evSetColumns($promotion, ['spent_minor' => 6000]);
    $input = evInput(['500.00']);

    expect(evDiscounts(evRun($input)))->toBe([])
        ->and(evVerdicts($input))->toBe(['budget|rejected|budget_exhausted']);

    evSetColumns($promotion, ['spent_minor' => 5000]);

    expect(evDiscounts(evRun(evInput(['500.00']))))->toBe(['order -50.00 budget']);
});

it('answers every presented code (G17)', function (): void {
    $autumn = cpnPromotion('autumn', ['effects' => [pdfPercent(1000)]]);
    cpnCoupon($autumn, 'AUTUMN');
    cpnCoupon($autumn, 'AUTUMN2');
    $other = cpnPromotion('other');
    $off = cpnCoupon($other, 'OFF');
    Promotions::coupons()->disable(new DisableCoupon($off->id));

    $result = evRun(evInput(['1000.00'], [' autumn ', 'NOPE', 'OFF', 'AUTUMN2', 'X5', 'X6']));

    expect(evDiscounts($result))->toBe(['order -100.00 autumn AUTUMN'])
        ->and(evRejections($result))->toBe([
            'promotions.catalog|unknown_code|NOPE',
            'promotions.catalog|coupon_disabled|OFF',
            'promotions.catalog|duplicate_promotion|AUTUMN2',
            'promotions.catalog|unknown_code|X5',
            'promotions.catalog|too_many_codes|X6',
        ]);
});

it('needs a known customer under the per-customer limit (G18)', function (): void {
    $promotion = evPromotion('once', PromotionStage::Cart, [pdfPercent(1000)], ['limits' => new UsageLimits(perCustomer: 1)]);
    DB::table('cmz_promotion_customer_usages')->insert([
        'promotion_id' => $promotion->id->value,
        'customer_ref' => 'customer:42',
        'used' => 1,
        'updated_at' => now(),
    ]);

    expect(evVerdicts(evInput(['1000.00'])))->toBe(['once|rejected|customer_required'])
        ->and(evVerdicts(evInput(['1000.00'], context: evContext('customer:42'))))->toBe(['once|rejected|per_customer_limit_reached'])
        ->and(evVerdicts(evInput(['1000.00'], context: evContext('customer:7'))))->toBe(['once|applied|100.00']);
});

it('does not apply a promotion with a rule no plugin contributes (G19)', function (): void {
    $promotion = cpnPromotion('missing', ['effects' => [pdfPercent(1000)]]);
    cpnCoupon($promotion, 'MISSING');
    DB::table('cmz_promotion_rules')->insert([
        'promotion_id' => $promotion->id->value,
        'role' => 'condition',
        'position' => 0,
        'rule_key' => 'acme.missing',
        'config_version' => 1,
        'config' => '{}',
    ]);
    $input = evInput(['1000.00'], ['MISSING']);
    $result = evRun($input);

    expect(evDiscounts($result))->toBe([])
        ->and(evRejections($result))->toBe(['promotions.cart|unsupported_rule|MISSING'])
        ->and(evVerdicts($input))->toBe(['missing|rejected|unsupported_rule']);
});

it('rounds a percentage by the configured mode (G20)', function (string $mode, string $third, string $half): void {
    evRounding($mode);
    evPromotion('third', PromotionStage::Cart, [evLinePercent(3333)], ['conditions' => [new RuleSpec('line.sellable_in', ['sellables' => ['fake:item-1']])]]);
    evPromotion('half', PromotionStage::Cart, [evLinePercent(5000)], ['conditions' => [new RuleSpec('line.sellable_in', ['sellables' => ['fake:item-2']])]]);

    expect(evDiscounts(evRun(evInput(['0.10', '0.05']))))->toBe(["line:1 {$third} third", "line:2 {$half} half"]);
})->with([
    'half up' => ['half_up', '-0.03', '-0.03'],
    'half even' => ['half_even', '-0.03', '-0.02'],
]);

it('spreads an order discount to the minor unit and folds it once per promotion (G21)', function (): void {
    $promotion = evPromotion('ten', PromotionStage::Cart, [evOrderFixed('10.00')]);

    $result = evRun(evInput(['10.00', '10.00', '10.00']));
    $shares = array_map(static fn ($line): string => $line->adjustments[0]->amount->toDecimal(), $result->lines);

    expect($shares)->toBe(['-3.34', '-3.33', '-3.33'])
        ->and($result->appliedPromotions())->toEqual([new AppliedPromotion($promotion->id->value, null, Money::of('10.00', 'RUB'))]);
});
