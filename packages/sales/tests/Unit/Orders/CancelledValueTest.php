<?php

declare(strict_types=1);

use Commerz\Money\Money;
use Commerz\Sales\Common\Internal\OrderStatus;

// The amount due shrinks by the exact share of the cancelled units (largest remainder), never by a rounded unit price.

it('takes the exact share of the line total for the cancelled units', function (int $cancelled, int $value): void {
    expect(OrderStatus::cancelledValue(Money::ofMinor(1000, 'RUB'), 3, $cancelled))->toEqual(Money::ofMinor($value, 'RUB'));
})->with([
    'none' => [0, 0],
    'one of three' => [1, 333],
    'two of three' => [2, 667],
    'all' => [3, 1000],
]);

it('cancels 333 and then 334 of 1000 for three units, leaving 333', function (): void {
    $total = Money::ofMinor(1000, 'RUB');
    $first = OrderStatus::cancelledValue($total, 3, 1);
    $second = OrderStatus::cancelledValue($total, 3, 2)->minus($first);
    $left = $total->minus(OrderStatus::cancelledValue($total, 3, 2));

    expect([$first->amountMinor, $second->amountMinor, $left->amountMinor])->toBe([333, 334, 333]);
});

it('never cancels more than the line total nor leaves a remainder when all units go', function (int $minor, int $quantity): void {
    $total = Money::ofMinor($minor, 'RUB');

    foreach (range(0, $quantity) as $cancelled) {
        $value = OrderStatus::cancelledValue($total, $quantity, $cancelled)->amountMinor;

        expect($value)->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual($minor);
    }

    expect(OrderStatus::cancelledValue($total, $quantity, $quantity)->amountMinor)->toBe($minor);
})->with([[1, 3], [999, 7], [100000, 1], [12345, 11], [0, 2]]);
