<?php

declare(strict_types=1);

use Commerz\Money\Money;
use Commerz\Money\RoundingMode;
use Commerz\Promotions\Rules\Data\Discount;
use Commerz\Promotions\Rules\Data\RuleParams;
use Commerz\Promotions\Rules\Enums\PromotionStage;
use Commerz\Promotions\Rules\Exceptions\InvalidRuleConfig;
use Commerz\Promotions\Rules\Internal\Effects\DeliveryPercentOff;
use Commerz\Promotions\Rules\Internal\Effects\LineFixedOff;
use Commerz\Promotions\Rules\Internal\Effects\LinePercentOff;
use Commerz\Promotions\Rules\Internal\Effects\OrderFixedOff;
use Commerz\Promotions\Rules\Internal\Effects\OrderPercentOff;
use Commerz\Promotions\Tests\Support\RuleStates;
use Commerz\Quotes\Data\Adjustment;
use Commerz\Quotes\Enums\AdjustmentTarget;

function effField(Closure $call): ?string
{
    try {
        $call();
    } catch (InvalidRuleConfig $e) {
        return $e->field;
    }

    return null;
}

function effNormalize(object $effect, array $params, string $currency = 'RUB'): array
{
    return $effect->normalize(RuleParams::fromInput($params), RuleStates::scope(PromotionStage::Cart, $currency))->toArray();
}

/** @return list<string> "line:amount" / "order:amount" / "delivery:amount" */
function effDescribe(array $discounts): array
{
    return array_map(static fn (Discount $d): string => match ($d->target) {
        AdjustmentTarget::Line => "line {$d->lineId}: {$d->amount->toDecimal()}",
        AdjustmentTarget::Order => "order: {$d->amount->toDecimal()}",
        AdjustmentTarget::Delivery => "delivery: {$d->amount->toDecimal()}",
    }, $discounts);
}

dataset('effects', [
    'line.percent_off' => [new LinePercentOff, [PromotionStage::Catalog, PromotionStage::Cart]],
    'line.fixed_off' => [new LineFixedOff, [PromotionStage::Catalog, PromotionStage::Cart]],
    'order.percent_off' => [new OrderPercentOff, [PromotionStage::Cart]],
    'order.fixed_off' => [new OrderFixedOff, [PromotionStage::Cart]],
    'delivery.percent_off' => [new DeliveryPercentOff, [PromotionStage::Delivery]],
]);

it('declares version 1 and its stages and cannot upgrade', function (object $effect, array $stages): void {
    expect($effect->version())->toBe(1)
        ->and($effect->stages())->toBe($stages)
        ->and(effField(fn () => $effect->upgrade(RuleParams::fromInput([]), 2)))->toBe('version');
})->with('effects');

it('normalizes the rate of the percent effects and refuses 0, over 10000, a stray key and a missing rate', function (object $effect): void {
    expect(effNormalize($effect, ['rateBp' => 1000]))->toBe(['rateBp' => 1000])
        ->and(effNormalize($effect, ['rateBp' => 10_000]))->toBe(['rateBp' => 10_000])
        ->and(effField(fn () => effNormalize($effect, ['rateBp' => 0])))->toBe('rateBp')
        ->and(effField(fn () => effNormalize($effect, ['rateBp' => 10_001])))->toBe('rateBp')
        ->and(effField(fn () => effNormalize($effect, ['rateBp' => 100, 'x' => 1])))->toBe('x')
        ->and(effField(fn () => effNormalize($effect, [])))->toBe('rateBp');
})->with([
    'line.percent_off' => [new LinePercentOff],
    'order.percent_off' => [new OrderPercentOff],
    'delivery.percent_off' => [new DeliveryPercentOff],
]);

it('normalizes the amount of the fixed effects and refuses zero, a foreign currency and a promotion without one', function (object $effect): void {
    $amount = ['amount_minor' => 3000, 'currency' => 'RUB'];

    expect(effNormalize($effect, ['amount' => Money::of('30.00', 'RUB')]))->toHaveKey('amount', $amount)
        ->and(effField(fn () => effNormalize($effect, ['amount' => Money::of('0.00', 'RUB')])))->toBe('amount')
        ->and(effField(fn () => effNormalize($effect, ['amount' => Money::of('1.00', 'EUR')])))->toBe('amount')
        ->and(effField(fn () => $effect->normalize(RuleParams::fromInput(['amount' => Money::of('1.00', 'RUB')]), RuleStates::scope(PromotionStage::Cart, null))))->toBe('amount')
        ->and(effField(fn () => effNormalize($effect, ['amount' => Money::of('1.00', 'RUB'), 'x' => 1])))->toBe('x')
        ->and(effField(fn () => effNormalize($effect, [])))->toBe('amount');
})->with([
    'line.fixed_off' => [new LineFixedOff],
    'order.fixed_off' => [new OrderFixedOff],
]);

it('writes the default unit of line.fixed_off explicitly', function (): void {
    $amount = Money::of('30.00', 'RUB');

    expect(effNormalize(new LineFixedOff, ['amount' => $amount]))->toBe(['amount' => ['amount_minor' => 3000, 'currency' => 'RUB'], 'per' => 'unit'])
        ->and(effNormalize(new LineFixedOff, ['per' => 'line', 'amount' => $amount]))->toHaveKey('per', 'line')
        ->and(effField(fn () => effNormalize(new LineFixedOff, ['amount' => $amount, 'per' => 'pair'])))->toBe('per');
});

it('takes a percentage of the current net of each eligible line, sequentially (D15)', function (): void {
    $state = RuleStates::state([['id' => '1', 'unit' => '1000.00'], ['id' => '2', 'unit' => '500.00'], ['id' => '3', 'unit' => '100.00']])
        ->withAdjustment(Adjustment::line('1', Money::of('-100.00', 'RUB'), 'promotions.catalog', 'earlier'));

    $discounts = (new LinePercentOff)->apply(RuleStates::effect($state, ['rateBp' => 500], ['1', '2']));

    expect(effDescribe($discounts))->toBe(['line 1: 45.00', 'line 2: 25.00']);
});

it('rounds a line percentage by the configured mode and omits a zero discount (G20)', function (): void {
    $effect = new LinePercentOff;
    $cheap = RuleStates::state([['id' => '1', 'unit' => '0.10']]);
    $half = RuleStates::state([['id' => '1', 'unit' => '0.05']]);

    expect(effDescribe($effect->apply(RuleStates::effect($cheap, ['rateBp' => 3333]))))->toBe(['line 1: 0.03'])
        ->and(effDescribe($effect->apply(RuleStates::effect($cheap, ['rateBp' => 3333], rounding: RoundingMode::HalfEven))))->toBe(['line 1: 0.03'])
        ->and(effDescribe($effect->apply(RuleStates::effect($half, ['rateBp' => 5000]))))->toBe(['line 1: 0.03'])
        ->and(effDescribe($effect->apply(RuleStates::effect($half, ['rateBp' => 5000], rounding: RoundingMode::HalfEven))))->toBe(['line 1: 0.02'])
        ->and($effect->apply(RuleStates::effect(RuleStates::state([['id' => '1', 'unit' => '0.01']]), ['rateBp' => 10])))->toBe([]);
});

it('takes a fixed amount per unit or per line, never above the line net (G8)', function (): void {
    $state = RuleStates::state([['id' => '1', 'unit' => '100.00', 'qty' => 3], ['id' => '2', 'unit' => '10.00']]);
    $effect = new LineFixedOff;

    expect(effDescribe($effect->apply(RuleStates::effect($state, ['amount' => Money::of('30.00', 'RUB')]))))->toBe(['line 1: 90.00', 'line 2: 10.00'])
        ->and(effDescribe($effect->apply(RuleStates::effect($state, ['amount' => Money::of('30.00', 'RUB'), 'per' => 'line']))))->toBe(['line 1: 30.00', 'line 2: 10.00'])
        ->and(effDescribe($effect->apply(RuleStates::effect($state, ['amount' => Money::of('500.00', 'RUB')], ['1']))))->toBe(['line 1: 300.00']);
});

it('measures a fixed line discount from the current net, so a line at zero gets nothing', function (): void {
    $state = RuleStates::state([['id' => '1', 'unit' => '10.00']])->withAdjustment(Adjustment::line('1', Money::of('-10.00', 'RUB'), 'promotions.catalog', 'free'));

    expect((new LineFixedOff)->apply(RuleStates::effect($state, ['amount' => Money::of('5.00', 'RUB')])))->toBe([])
        ->and((new LinePercentOff)->apply(RuleStates::effect($state, ['rateBp' => 5000])))->toBe([]);
});

it('takes the order percentage from the items net less the order adjustments already in the state (G6)', function (): void {
    $state = RuleStates::state([['id' => '1', 'unit' => '1000.00']])
        ->withAdjustment(Adjustment::line('1', Money::of('-100.00', 'RUB'), 'promotions.catalog', 'catalog'));

    expect(effDescribe((new OrderPercentOff)->apply(RuleStates::effect($state, ['rateBp' => 1000]))))->toBe(['order: 90.00']);

    $stacked = $state->withAdjustment(Adjustment::order(Money::of('-90.00', 'RUB'), 'promotions.cart', 'first'));
    expect(effDescribe((new OrderPercentOff)->apply(RuleStates::effect($stacked, ['rateBp' => 1000]))))->toBe(['order: 81.00']);
});

it('clamps a fixed order discount to what is left of the order (G7) and gives nothing at zero', function (): void {
    $state = RuleStates::state([['id' => '1', 'unit' => '100.00'], ['id' => '2', 'unit' => '200.00']]);
    $effect = new OrderFixedOff;

    expect(effDescribe($effect->apply(RuleStates::effect($state, ['amount' => Money::of('500.00', 'RUB')]))))->toBe(['order: 300.00'])
        ->and(effDescribe($effect->apply(RuleStates::effect($state, ['amount' => Money::of('50.00', 'RUB')]))))->toBe(['order: 50.00']);

    $spent = $state->withAdjustment(Adjustment::order(Money::of('-300.00', 'RUB'), 'promotions.cart', 'all'));
    expect($effect->apply(RuleStates::effect($spent, ['amount' => Money::of('50.00', 'RUB')])))->toBe([])
        ->and((new OrderPercentOff)->apply(RuleStates::effect($spent, ['rateBp' => 5000])))->toBe([]);
});

it('takes a percentage of the delivery net and nothing without a delivery (G15)', function (): void {
    $effect = new DeliveryPercentOff;
    $lines = [['id' => '1', 'unit' => '100.00']];

    $delivered = RuleStates::state($lines, delivery: '300.00');
    expect(effDescribe($effect->apply(RuleStates::effect($delivered, ['rateBp' => 10_000]))))->toBe(['delivery: 300.00'])
        ->and(effDescribe($effect->apply(RuleStates::effect($delivered, ['rateBp' => 2500]))))->toBe(['delivery: 75.00'])
        ->and($effect->apply(RuleStates::effect(RuleStates::state($lines), ['rateBp' => 10_000])))->toBe([]);

    $discounted = $delivered->withAdjustment(Adjustment::delivery(Money::of('-100.00', 'RUB'), 'promotions.delivery', 'earlier'));
    expect(effDescribe($effect->apply(RuleStates::effect($discounted, ['rateBp' => 5000]))))->toBe(['delivery: 100.00']);

    $free = $delivered->withAdjustment(Adjustment::delivery(Money::of('-300.00', 'RUB'), 'promotions.delivery', 'free'));
    expect($effect->apply(RuleStates::effect($free, ['rateBp' => 5000])))->toBe([]);
});

it('clamps a per-unit fixed discount whose amount times quantity exceeds int64', function (): void {
    $effect = new LineFixedOff;
    $stored = $effect->normalize(RuleParams::fromInput([
        'amount' => Money::ofMinor(intdiv(PHP_INT_MAX, 2) + 1, 'RUB'),
        'per' => 'unit',
    ]), RuleStates::scope())->toArray();
    $state = RuleStates::state([['id' => '1', 'unit' => '10.00', 'qty' => 2]]);

    expect(effDescribe($effect->apply(RuleStates::effect($state, $stored))))->toBe(['line 1: 20.00']);
});

it('refuses stored fixed amounts that are not positive instead of discounting by them', function (): void {
    $state = RuleStates::state([['id' => '1', 'unit' => '10.00']]);

    foreach ([new LineFixedOff, new OrderFixedOff] as $effect) {
        foreach ([0, -1] as $minor) {
            expect(effField(fn () => $effect->apply(RuleStates::effect($state, ['amount' => Money::ofMinor($minor, 'RUB'), 'per' => 'unit']))))->toBe('amount');
        }
    }
});
