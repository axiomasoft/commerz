<?php

declare(strict_types=1);

use Commerz\Errors\InvalidCommand;
use Commerz\Money\Money;
use Commerz\Money\RoundingMode;
use Commerz\Promotions\Rules\Data\Discount;
use Commerz\Promotions\Rules\Data\RuleParams;
use Commerz\Promotions\Rules\Enums\PromotionStage;
use Commerz\Promotions\Rules\Exceptions\InvalidRuleConfig;
use Commerz\Promotions\Rules\Internal\Effects\BuyXGetY;
use Commerz\Promotions\Rules\Internal\Effects\UnitPool;
use Commerz\Promotions\Tests\Support\RuleStates;
use Commerz\Quotes\Data\Adjustment;

function bxgyField(array $params): ?string
{
    try {
        (new BuyXGetY)->normalize(RuleParams::fromInput($params), RuleStates::scope());
    } catch (InvalidRuleConfig $e) {
        return $e->field;
    }

    return null;
}

/** @return list<string> */
function bxgy(array $params, array $lines, ?array $eligible = null, RoundingMode $rounding = RoundingMode::HalfUp, ?Closure $with = null): array
{
    $state = RuleStates::state($lines);
    $state = $with === null ? $state : $with($state);

    $effect = new BuyXGetY;
    $stored = $effect->normalize(RuleParams::fromInput($params), RuleStates::scope())->toArray();

    return array_map(
        static fn (Discount $d): string => "{$d->lineId}: {$d->amount->toDecimal()}",
        $effect->apply(RuleStates::effect($state, $stored, $eligible, $rounding)),
    );
}

function g9Lines(): array
{
    return [['id' => 'A', 'unit' => '100.00', 'qty' => 3], ['id' => 'B', 'unit' => '50.00'], ['id' => 'C', 'unit' => '80.00', 'qty' => 2]];
}

it('is a cart-stage effect of version 1', function (): void {
    expect((new BuyXGetY)->key())->toBe('buy_x_get_y')
        ->and((new BuyXGetY)->version())->toBe(1)
        ->and((new BuyXGetY)->stages())->toBe([PromotionStage::Cart]);
});

it('writes the defaults explicitly and sorts the keys', function (): void {
    $normalized = (new BuyXGetY)->normalize(RuleParams::fromInput(['get' => 1, 'buy' => 2]), RuleStates::scope());

    expect($normalized->toArray())->toBe(['buy' => 2, 'get' => 1, 'maxApplications' => null, 'rateBp' => 10_000])
        ->and(array_keys($normalized->toArray()))->toBe(['buy', 'get', 'maxApplications', 'rateBp']);

    $full = (new BuyXGetY)->normalize(RuleParams::fromInput(['rateBp' => 5000, 'maxApplications' => 3, 'get' => 1, 'buy' => 2]), RuleStates::scope());
    expect($full->toArray())->toBe(['buy' => 2, 'get' => 1, 'maxApplications' => 3, 'rateBp' => 5000]);
});

it('refuses out-of-range and missing parameters and a stray key', function (): void {
    expect(bxgyField(['buy' => 0, 'get' => 1]))->toBe('buy')
        ->and(bxgyField(['buy' => 1001, 'get' => 1]))->toBe('buy')
        ->and(bxgyField(['buy' => 1, 'get' => 0]))->toBe('get')
        ->and(bxgyField(['buy' => 1, 'get' => 1001]))->toBe('get')
        ->and(bxgyField(['buy' => 1, 'get' => 1, 'rateBp' => 0]))->toBe('rateBp')
        ->and(bxgyField(['buy' => 1, 'get' => 1, 'rateBp' => 10_001]))->toBe('rateBp')
        ->and(bxgyField(['buy' => 1, 'get' => 1, 'maxApplications' => 0]))->toBe('maxApplications')
        ->and(bxgyField(['get' => 1]))->toBe('buy')
        ->and(bxgyField(['buy' => 1]))->toBe('get')
        ->and(bxgyField(['buy' => 1, 'get' => 1, 'x' => 1]))->toBe('x');
});

it('cannot upgrade from another version', function (): void {
    expect(fn () => (new BuyXGetY)->upgrade(RuleParams::fromInput([]), 2))->toThrow(InvalidRuleConfig::class);
});

it('makes the cheapest units free across lines (G9)', function (): void {
    expect(bxgy(['buy' => 2, 'get' => 1, 'rateBp' => 10_000], g9Lines()))->toBe(['B: 50.00', 'C: 80.00']);
});

it('limits the applications (G10)', function (): void {
    expect(bxgy(['buy' => 2, 'get' => 1, 'maxApplications' => 1], g9Lines()))->toBe(['B: 50.00']);
});

it('discounts the selected units by the rate (G11)', function (): void {
    expect(bxgy(['buy' => 2, 'get' => 1, 'rateBp' => 5000], g9Lines()))->toBe(['B: 25.00', 'C: 40.00']);
});

it('takes the cheapest unit of a line whose net is not divisible by its quantity (G12)', function (): void {
    $discounted = static fn ($state) => $state->withAdjustment(Adjustment::line('A', Money::of('-0.01', 'RUB'), 'promotions.catalog', 'catalog'));

    expect(bxgy(['buy' => 2, 'get' => 1], [['id' => 'A', 'unit' => '100.00', 'qty' => 3]], with: $discounted))->toBe(['A: 99.99']);
});

it('gives nothing for fewer units than buy + get', function (): void {
    expect(bxgy(['buy' => 2, 'get' => 1], [['id' => 'A', 'unit' => '100.00', 'qty' => 2]]))->toBe([]);
});

it('works only on the eligible lines', function (): void {
    expect(bxgy(['buy' => 1, 'get' => 1], g9Lines(), ['A', 'C']))->toBe(['C: 160.00'])
        ->and(bxgy(['buy' => 1, 'get' => 1], g9Lines(), ['B']))->toBe([]);
});

it('breaks a tie of equal values by the lower line index', function (): void {
    $lines = [['id' => 'X', 'unit' => '10.00'], ['id' => 'Y', 'unit' => '10.00']];

    expect(bxgy(['buy' => 1, 'get' => 1], $lines))->toBe(['X: 10.00'])
        ->and(bxgy(['buy' => 1, 'get' => 1], array_reverse($lines)))->toBe(['Y: 10.00']);
});

it('rounds the discount of a partial rate by the configured mode', function (): void {
    $lines = [['id' => 'A', 'unit' => '0.05'], ['id' => 'B', 'unit' => '1.00']];

    expect(bxgy(['buy' => 1, 'get' => 1, 'rateBp' => 5000], $lines))->toBe(['A: 0.03'])
        ->and(bxgy(['buy' => 1, 'get' => 1, 'rateBp' => 5000], $lines, rounding: RoundingMode::HalfEven))->toBe(['A: 0.02']);
});

it('handles a quantity of a million without materializing the units', function (): void {
    $started = hrtime(true);
    $discounts = bxgy(['buy' => 2, 'get' => 1], [['id' => 'A', 'unit' => '1.00', 'qty' => 1_000_000]]);

    expect($discounts)->toBe(['A: 333333.00'])
        ->and((hrtime(true) - $started) / 1e9)->toBeLessThan(0.5);
});

it('splits a line net into units that add up to it exactly', function (): void {
    $pool = UnitPool::of([[29_999, 3], [1000, 1]]);

    expect($pool->units)->toBe(4)
        ->and($pool->groups)->toBe([[1000, 1, 1], [9999, 0, 1], [10_000, 0, 2]])
        ->and($pool->cheapest(4))->toEqual([0 => 29_999, 1 => 1000])
        ->and($pool->cheapest(0))->toBe([])
        ->and($pool->cheapest(1))->toBe([1 => 1000])
        ->and($pool->cheapest(2))->toEqual([0 => 9999, 1 => 1000])
        ->and($pool->cheapest(100))->toEqual([0 => 29_999, 1 => 1000]);
});

it('refuses a line with a negative net or no quantity', function (): void {
    expect(fn () => UnitPool::of([[-1, 1]]))->toThrow(InvalidCommand::class)
        ->and(fn () => UnitPool::of([[100, 0]]))->toThrow(InvalidCommand::class);
});

it('treats a line whose net went below zero as free units and discounts nothing for it', function (): void {
    $negative = static fn ($state) => $state->withAdjustment(Adjustment::line('A', Money::of('-15.00', 'RUB'), 'promotions.catalog', 'catalog'));

    expect(bxgy(['buy' => 1, 'get' => 1], [['id' => 'A', 'unit' => '10.00']], with: $negative))->toBe([])
        ->and(bxgy(['buy' => 1, 'get' => 1], [['id' => 'A', 'unit' => '10.00'], ['id' => 'B', 'unit' => '4.00']], with: $negative))->toBe([]);
});

it('refuses a pool whose unit count does not fit an integer', function (): void {
    expect(fn () => UnitPool::of([[0, PHP_INT_MAX], [0, PHP_INT_MAX]]))->toThrow(InvalidCommand::class);
});
