<?php

declare(strict_types=1);

use Commerz\Money\Money;
use Commerz\Promotions\Rules\Data\RuleParams;
use Commerz\Promotions\Rules\Enums\ConditionRole;
use Commerz\Promotions\Rules\Enums\PromotionStage;
use Commerz\Promotions\Rules\Exceptions\InvalidRuleConfig;
use Commerz\Promotions\Rules\Internal\Conditions\CartQuantityAtLeast;
use Commerz\Promotions\Rules\Internal\Conditions\CartSubtotalAtLeast;
use Commerz\Promotions\Rules\Internal\Conditions\CustomerInGroup;
use Commerz\Promotions\Rules\Internal\Conditions\LineCategoryIn;
use Commerz\Promotions\Rules\Internal\Conditions\LineSellableIn;
use Commerz\Promotions\Tests\Support\RuleStates;
use Commerz\Quotes\Data\Adjustment;

/** The field of the InvalidRuleConfig a call fails with, or null. */
function condField(Closure $call): ?string
{
    try {
        $call();
    } catch (InvalidRuleConfig $e) {
        return $e->field;
    }

    return null;
}

function condNormalize(object $condition, array $params, string $currency = 'RUB'): array
{
    return $condition->normalize(RuleParams::fromInput($params), RuleStates::scope(PromotionStage::Cart, $currency))->toArray();
}

dataset('conditions', [
    'line.sellable_in' => [new LineSellableIn, ConditionRole::LineFilter, [PromotionStage::Catalog, PromotionStage::Cart, PromotionStage::Delivery]],
    'line.category_in' => [new LineCategoryIn, ConditionRole::LineFilter, [PromotionStage::Catalog, PromotionStage::Cart, PromotionStage::Delivery]],
    'customer.in_group' => [new CustomerInGroup, ConditionRole::Qualifier, [PromotionStage::Catalog, PromotionStage::Cart, PromotionStage::Delivery]],
    'cart.subtotal_at_least' => [new CartSubtotalAtLeast, ConditionRole::Qualifier, [PromotionStage::Cart, PromotionStage::Delivery]],
    'cart.quantity_at_least' => [new CartQuantityAtLeast, ConditionRole::Qualifier, [PromotionStage::Cart, PromotionStage::Delivery]],
]);

it('declares its key, version 1, role and stages', function (object $condition, ConditionRole $role, array $stages): void {
    expect($condition->version())->toBe(1)
        ->and($condition->role())->toBe($role)
        ->and($condition->stages())->toBe($stages);
})->with('conditions');

it('cannot upgrade from another version', function (object $condition, ConditionRole $role): void {
    expect(condField(fn () => $condition->upgrade(RuleParams::fromInput([]), 2)))->toBe('version');
})->with('conditions');

// line.sellable_in

it('sorts and deduplicates sellables and refuses a malformed ref, a stray key and an empty list', function (): void {
    $condition = new LineSellableIn;

    expect(condNormalize($condition, ['sellables' => ['variant:b', 'variant:a', 'variant:b']]))->toBe(['sellables' => ['variant:a', 'variant:b']])
        ->and(condField(fn () => condNormalize($condition, ['sellables' => ['nocolon']])))->toBe('sellables')
        ->and(condField(fn () => condNormalize($condition, ['sellables' => []])))->toBe('sellables')
        ->and(condField(fn () => condNormalize($condition, ['sellables' => ['variant:a'], 'extra' => 1])))->toBe('extra')
        ->and(condField(fn () => condNormalize($condition, [])))->toBe('sellables');
});

it('keeps only the lines whose sellable is listed, in the input order', function (): void {
    $state = RuleStates::state([
        ['id' => '1', 'unit' => '10.00'],
        ['id' => '2', 'unit' => '20.00'],
        ['id' => '3', 'unit' => '30.00'],
    ]);
    $condition = new LineSellableIn;

    $passed = $condition->evaluate(RuleStates::condition($state, ['sellables' => ['variant:3', 'variant:1']]));
    expect($passed->passed)->toBeTrue()->and($passed->lines)->toBe(['1', '3']);

    $narrowed = $condition->evaluate(RuleStates::condition($state, ['sellables' => ['variant:3', 'variant:1']], ['3', '2']));
    expect($narrowed->lines)->toBe(['3']);

    $none = $condition->evaluate(RuleStates::condition($state, ['sellables' => ['variant:9']]));
    expect($none->passed)->toBeFalse()->and($none->reason)->toBe('no_eligible_lines');
});

// line.category_in

it('sorts and deduplicates categories and refuses a bad code', function (): void {
    $condition = new LineCategoryIn;

    expect(condNormalize($condition, ['categories' => ['shoes', 'bags', 'shoes']]))->toBe(['categories' => ['bags', 'shoes']])
        ->and(condField(fn () => condNormalize($condition, ['categories' => ['Shoes']])))->toBe('categories')
        ->and(condField(fn () => condNormalize($condition, ['categories' => []])))->toBe('categories')
        ->and(condField(fn () => condNormalize($condition, ['categories' => ['shoes'], 'x' => 1])))->toBe('x');
});

it('keeps the lines of the listed categories, ancestors included', function (): void {
    $state = RuleStates::state([
        ['id' => '1', 'unit' => '10.00', 'categories' => ['footwear', 'shoes']],
        ['id' => '2', 'unit' => '20.00', 'categories' => ['hats']],
        ['id' => '3', 'unit' => '30.00'],
    ]);
    $condition = new LineCategoryIn;

    expect($condition->evaluate(RuleStates::condition($state, ['categories' => ['footwear']]))->lines)->toBe(['1'])
        ->and($condition->evaluate(RuleStates::condition($state, ['categories' => ['footwear', 'hats']]))->lines)->toBe(['1', '2']);

    $none = $condition->evaluate(RuleStates::condition($state, ['categories' => ['bags']]));
    expect($none->passed)->toBeFalse()->and($none->reason)->toBe('no_eligible_lines');
});

// customer.in_group

it('sorts and deduplicates groups and refuses a bad name', function (): void {
    $condition = new CustomerInGroup;

    expect(condNormalize($condition, ['groups' => ['vip', 'b2b', 'vip']]))->toBe(['groups' => ['b2b', 'vip']])
        ->and(condField(fn () => condNormalize($condition, ['groups' => ['VIP']])))->toBe('groups')
        ->and(condField(fn () => condNormalize($condition, ['groups' => range(1, 51)])))->toBe('groups');
});

it('passes only when the customer is in one of the groups', function (): void {
    $condition = new CustomerInGroup;
    $line = [['id' => '1', 'unit' => '10.00']];

    expect($condition->evaluate(RuleStates::condition(RuleStates::state($line, ['wholesale', 'vip']), ['groups' => ['vip']]))->passed)->toBeTrue();

    $guest = $condition->evaluate(RuleStates::condition(RuleStates::state($line), ['groups' => ['vip']]));
    expect($guest->passed)->toBeFalse()->and($guest->reason)->toBe('customer_group_mismatch');

    $other = $condition->evaluate(RuleStates::condition(RuleStates::state($line, ['retail']), ['groups' => ['vip']]));
    expect($other->reason)->toBe('customer_group_mismatch');
});

// cart.subtotal_at_least

it('writes the default lines explicitly and refuses a zero amount, a foreign currency and a promotion without currency', function (): void {
    $condition = new CartSubtotalAtLeast;
    $amount = ['amount_minor' => 300000, 'currency' => 'RUB'];

    expect(condNormalize($condition, ['amount' => Money::of('3000.00', 'RUB')]))->toBe(['amount' => $amount, 'lines' => 'all'])
        ->and(condNormalize($condition, ['lines' => 'eligible', 'amount' => Money::of('3000.00', 'RUB')]))->toBe(['amount' => $amount, 'lines' => 'eligible'])
        ->and(condField(fn () => condNormalize($condition, ['amount' => Money::of('0.00', 'RUB')])))->toBe('amount')
        ->and(condField(fn () => condNormalize($condition, ['amount' => Money::of('5.00', 'USD')])))->toBe('amount')
        ->and(condField(fn () => $condition->normalize(RuleParams::fromInput(['amount' => Money::of('5.00', 'RUB')]), RuleStates::scope(PromotionStage::Cart, null))))->toBe('amount')
        ->and(condField(fn () => condNormalize($condition, ['amount' => Money::of('5.00', 'RUB'), 'lines' => 'some'])))->toBe('lines')
        ->and(condField(fn () => condNormalize($condition, ['amount' => Money::of('5.00', 'RUB'), 'x' => 1])))->toBe('x')
        ->and(condField(fn () => condNormalize($condition, [])))->toBe('amount');
});

it('compares the net of the lines, after the discounts already in the state, with the minimum', function (): void {
    $condition = new CartSubtotalAtLeast;
    $state = RuleStates::state([['id' => '1', 'unit' => '1000.00'], ['id' => '2', 'unit' => '2000.00']])
        ->withAdjustment(Adjustment::line('2', Money::of('-300.00', 'RUB'), 'promotions.catalog', 'test'));
    $params = static fn (string $amount, string $lines = 'all'): array => ['amount' => Money::of($amount, 'RUB'), 'lines' => $lines];

    expect($condition->evaluate(RuleStates::condition($state, $params('2700.00')))->passed)->toBeTrue();

    $short = $condition->evaluate(RuleStates::condition($state, $params('2700.01')));
    expect($short->passed)->toBeFalse()->and($short->reason)->toBe('minimum_not_reached');

    expect($condition->evaluate(RuleStates::condition($state, $params('1700.00', 'eligible'), ['2']))->passed)->toBeTrue()
        ->and($condition->evaluate(RuleStates::condition($state, $params('1700.01', 'eligible'), ['2']))->passed)->toBeFalse()
        ->and($condition->evaluate(RuleStates::condition($state, $params('1700.01', 'all'), ['2']))->passed)->toBeTrue();
});

// cart.quantity_at_least

it('normalizes the quantity with the lines default and refuses the bounds', function (): void {
    $condition = new CartQuantityAtLeast;

    expect(condNormalize($condition, ['quantity' => 3]))->toBe(['lines' => 'all', 'quantity' => 3])
        ->and(condNormalize($condition, ['quantity' => 1_000_000, 'lines' => 'eligible']))->toBe(['lines' => 'eligible', 'quantity' => 1_000_000])
        ->and(condField(fn () => condNormalize($condition, ['quantity' => 0])))->toBe('quantity')
        ->and(condField(fn () => condNormalize($condition, ['quantity' => 1_000_001])))->toBe('quantity')
        ->and(condField(fn () => condNormalize($condition, ['quantity' => 2, 'x' => 1])))->toBe('x')
        ->and(condField(fn () => condNormalize($condition, [])))->toBe('quantity');
});

it('counts the units of all the lines or of the eligible ones', function (): void {
    $condition = new CartQuantityAtLeast;
    $state = RuleStates::state([['id' => '1', 'unit' => '10.00', 'qty' => 2], ['id' => '2', 'unit' => '10.00', 'qty' => 3]]);

    expect($condition->evaluate(RuleStates::condition($state, ['quantity' => 5]))->passed)->toBeTrue()
        ->and($condition->evaluate(RuleStates::condition($state, ['quantity' => 5, 'lines' => 'eligible'], ['2']))->passed)->toBeFalse()
        ->and($condition->evaluate(RuleStates::condition($state, ['quantity' => 3, 'lines' => 'eligible'], ['2']))->passed)->toBeTrue();

    $short = $condition->evaluate(RuleStates::condition($state, ['quantity' => 6]));
    expect($short->reason)->toBe('quantity_not_reached');
});

it('refuses a stored minimum that is not positive instead of qualifying every cart', function (): void {
    $state = RuleStates::state([['id' => '1', 'unit' => '10.00', 'qty' => 2]]);

    foreach ([0, -1] as $minor) {
        expect(condField(fn () => (new CartSubtotalAtLeast)->evaluate(RuleStates::condition($state, ['amount' => Money::ofMinor($minor, 'RUB'), 'lines' => 'all']))))->toBe('amount');
    }
});
