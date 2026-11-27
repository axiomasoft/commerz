<?php

declare(strict_types=1);

use Commerz\Errors\InvalidCommand;
use Commerz\Money\Money;
use Commerz\Money\MoneyAllocator;

/**
 * @param  list<Money>  $parts
 * @return list<int>
 */
function minors(array $parts): array
{
    return array_map(static fn (Money $m): int => $m->amountMinor, $parts);
}

it('splits 100 by three equal weights as 34, 33, 33', function (): void {
    expect(minors(MoneyAllocator::allocate(Money::ofMinor(100, 'RUB'), [1, 1, 1])))->toBe([34, 33, 33]);
});

it('uses the largest remainder and breaks ties by index', function (): void {
    // 10 split 1:2:3 -> exact 1.67, 3.33, 5.00 -> floors 1,3,5 (+1 left) -> remainder .67 wins
    expect(minors(MoneyAllocator::allocate(Money::ofMinor(10, 'USD'), [1, 2, 3])))->toBe([2, 3, 5]);
    // 5 across [1,1] -> tie at .5, lowest index first
    expect(minors(MoneyAllocator::allocate(Money::ofMinor(5, 'USD'), [1, 1])))->toBe([3, 2]);
});

it('gives zero weights nothing', function (): void {
    expect(minors(MoneyAllocator::allocate(Money::ofMinor(100, 'RUB'), [0, 1, 0, 2])))->toBe([0, 33, 0, 67]);
    expect(minors(MoneyAllocator::allocate(Money::ofMinor(1, 'RUB'), [0, 0, 5])))->toBe([0, 0, 1]);
});

it('splits negative totals symmetrically', function (): void {
    expect(minors(MoneyAllocator::allocate(Money::ofMinor(-100, 'RUB'), [1, 1, 1])))->toBe([-34, -33, -33]);
    expect(minors(MoneyAllocator::allocate(Money::zero('RUB'), [3, 7])))->toBe([0, 0]);
});

it('allocates zero-exponent currencies in whole units', function (): void {
    expect(minors(MoneyAllocator::allocate(Money::of('1000', 'JPY'), [1, 1, 1])))->toBe([334, 333, 333]);
});

it('keeps the sum exact and the currency for arbitrary splits', function (): void {
    mt_srand(7);
    for ($i = 0; $i < 200; $i++) {
        $total = mt_rand(-1_000_000, 1_000_000);
        $weights = array_map(static fn (): int => mt_rand(0, 50), range(1, mt_rand(1, 9)));
        if (array_sum($weights) === 0) {
            $weights[0] = 1;
        }
        $parts = MoneyAllocator::allocate(Money::ofMinor($total, 'EUR'), $weights);

        expect(array_sum(minors($parts)))->toBe($total)
            ->and($parts)->toHaveCount(count($weights))
            ->and($parts[0]->currency->code)->toBe('EUR');
    }
});

it('does not overflow on large totals and weights', function (): void {
    $parts = MoneyAllocator::allocate(Money::ofMinor(PHP_INT_MAX, 'JPY'), [PHP_INT_MAX, PHP_INT_MAX]);

    expect(array_sum(minors($parts)))->toBe(PHP_INT_MAX);
    expect(minors(MoneyAllocator::allocate(Money::ofMinor(PHP_INT_MIN, 'JPY'), [1, 1])))->toBe([PHP_INT_MIN / 2, PHP_INT_MIN / 2]);
});

it('rejects empty, negative and all-zero weights', function (array $weights): void {
    /** @var list<int> $weights */
    expect(fn () => MoneyAllocator::allocate(Money::ofMinor(100, 'RUB'), $weights))->toThrow(InvalidCommand::class);
})->with([[[]], [[1, -1]], [[0, 0]]]);
