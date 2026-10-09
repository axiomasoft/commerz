<?php

declare(strict_types=1);

use Commerz\Errors\InvalidCommand;
use Commerz\Money\Currency;
use Commerz\Money\CurrencyMismatch;
use Commerz\Money\InvalidCurrency;
use Commerz\Money\Money;
use Commerz\Money\Rate;
use Commerz\Money\RoundingMode;

it('parses decimal strings by currency exponent', function (string $amount, string $currency, int $minor): void {
    expect(Money::of($amount, $currency)->amountMinor)->toBe($minor);
})->with([
    ['19.90', 'RUB', 1990],
    ['19.9', 'RUB', 1990],
    ['-0.05', 'USD', -5],
    ['500', 'JPY', 500],
    ['1.234', 'KWD', 1234],
]);

it('rejects amounts finer than the currency allows or malformed', function (string $amount, string $currency): void {
    expect(fn () => Money::of($amount, $currency))->toThrow(InvalidCommand::class);
})->with([
    ['19.999', 'RUB'],
    ['10.5', 'JPY'],
    ['1e3', 'RUB'],
    ['', 'RUB'],
    ['9223372036854775808', 'JPY'],
]);

it('rejects unknown currencies with a stable code', function (): void {
    try {
        Currency::of('XXX');
        $this->fail('expected InvalidCurrency');
    } catch (InvalidCurrency $e) {
        expect($e->code())->toBe('money.invalid_currency')->and($e->context())->toHaveKey('reason');
    }
    expect(fn () => Currency::of('rub'))->toThrow(InvalidCurrency::class);
});

it('knows exponents', function (): void {
    expect(Currency::of('RUB')->exponent)->toBe(2)
        ->and(Currency::of('JPY')->exponent)->toBe(0)
        ->and(Currency::of('KRW')->exponent)->toBe(0)
        ->and(Currency::of('KZT')->exponent)->toBe(2)
        ->and((string) Currency::of('USD'))->toBe('USD');
});

it('adds, subtracts, multiplies and negates', function (): void {
    $a = Money::of('10.00', 'EUR');
    $b = Money::of('2.50', 'EUR');

    expect($a->plus($b)->amountMinor)->toBe(1250)
        ->and($a->minus($b)->amountMinor)->toBe(750)
        ->and($b->multiply(3)->amountMinor)->toBe(750)
        ->and($b->negate()->amountMinor)->toBe(-250)
        ->and($b->negate()->isNegative())->toBeTrue()
        ->and(Money::zero('EUR')->isZero())->toBeTrue()
        ->and($a->isPositive())->toBeTrue();
});

it('forbids mixing currencies', function (): void {
    $rub = Money::of('1', 'RUB');
    $usd = Money::of('1', 'USD');

    foreach ([fn () => $rub->plus($usd), fn () => $rub->minus($usd), fn () => $rub->compare($usd)] as $call) {
        try {
            $call();
            $this->fail('expected CurrencyMismatch');
        } catch (CurrencyMismatch $e) {
            expect($e->code())->toBe('money.currency_mismatch');
        }
    }
    expect($rub->equals($usd))->toBeFalse();
});

it('compares', function (): void {
    expect(Money::of('1', 'RUB')->compare(Money::of('2', 'RUB')))->toBe(-1)
        ->and(Money::of('2', 'RUB')->compare(Money::of('2', 'RUB')))->toBe(0)
        ->and(Money::of('3', 'RUB')->compare(Money::of('2', 'RUB')))->toBe(1)
        ->and(Money::of('2', 'RUB')->equals(Money::ofMinor(200, 'RUB')))->toBeTrue();
});

it('fails loudly on int64 overflow', function (): void {
    $max = Money::ofMinor(PHP_INT_MAX, 'JPY');

    expect(fn () => $max->plus(Money::ofMinor(1, 'JPY')))->toThrow(InvalidCommand::class)
        ->and(fn () => $max->multiply(2))->toThrow(InvalidCommand::class)
        ->and(fn () => Money::ofMinor(PHP_INT_MIN, 'JPY')->negate())->toThrow(InvalidCommand::class)
        ->and(fn () => $max->percentage(Rate::bp(20_000), RoundingMode::HalfUp))->toThrow(InvalidCommand::class);
});

it('applies percentages with explicit rounding', function (RoundingMode $mode, int $base, int $expected): void {
    // 12.5% of base
    expect(Money::ofMinor($base, 'USD')->percentage(Rate::percent('12.5'), $mode)->amountMinor)->toBe($expected);
})->with([
    'half up positive' => [RoundingMode::HalfUp, 4, 1],   // 0.5 -> 1
    'half down' => [RoundingMode::HalfDown, 4, 0],        // 0.5 -> 0
    'half even 0.5' => [RoundingMode::HalfEven, 4, 0],
    'half even 1.5' => [RoundingMode::HalfEven, 12, 2],   // 1.5 -> 2
    'up' => [RoundingMode::Up, 1, 1],                     // 0.125 -> 1
    'down' => [RoundingMode::Down, 15, 1],                // 1.875 -> 1
    'ceiling negative' => [RoundingMode::Ceiling, -15, -1],
    'floor negative' => [RoundingMode::Floor, -15, -2],
    'up negative' => [RoundingMode::Up, -1, -1],
    'exact' => [RoundingMode::HalfUp, 800, 100],
]);

it('formats a decimal string', function (): void {
    expect(Money::ofMinor(1990, 'RUB')->toDecimal())->toBe('19.90')
        ->and(Money::ofMinor(-5, 'USD')->toDecimal())->toBe('-0.05')
        ->and(Money::ofMinor(500, 'JPY')->toDecimal())->toBe('500');
});

it('parses rates', function (): void {
    expect(Rate::percent('20')->basisPoints)->toBe(2000)
        ->and(Rate::percent('12.5')->basisPoints)->toBe(1250)
        ->and(Rate::percent('0.01')->basisPoints)->toBe(1)
        ->and(Rate::bp(0)->isZero())->toBeTrue()
        ->and(fn () => Rate::percent('12.345'))->toThrow(InvalidCommand::class)
        ->and(fn () => Rate::percent('-1'))->toThrow(InvalidCommand::class)
        ->and(fn () => Rate::bp(-1))->toThrow(InvalidCommand::class);
});

it('extracts the tax already included in a gross amount', function (string $gross, int $bp, RoundingMode $mode, string $expected): void {
    expect(Money::of($gross, 'RUB')->includedPart(Rate::bp($bp), $mode)->toDecimal())->toBe($expected);
})->with([
    'round figure' => ['1220.00', 2200, RoundingMode::HalfUp, '220.00'],
    'rounded down' => ['99.99', 2200, RoundingMode::HalfUp, '18.03'],
    'zero rate' => ['99.99', 0, RoundingMode::HalfUp, '0.00'],
    'small amount' => ['0.05', 2000, RoundingMode::HalfUp, '0.01'], // 5 × 2000 / 12000 = 0.83
    'negative refund' => ['-1220.00', 2200, RoundingMode::HalfUp, '-220.00'],
]);

it('rounds an included part that lands exactly on .5 by the mode', function (RoundingMode $mode, int $expected): void {
    // 15 minor at 100% (10000 bp): 15 × 10000 / 20000 = 7.5
    expect(Money::ofMinor(15, 'USD')->includedPart(Rate::bp(10_000), $mode)->amountMinor)->toBe($expected);
})->with([
    'half up' => [RoundingMode::HalfUp, 8],
    'half down' => [RoundingMode::HalfDown, 7],
    'half even' => [RoundingMode::HalfEven, 8],
]);

it('keeps an included part within the amount even at the int64 edge', function (): void {
    expect(Money::ofMinor(PHP_INT_MAX, 'JPY')->includedPart(Rate::bp(10_000), RoundingMode::Down)->amountMinor)->toBe(intdiv(PHP_INT_MAX, 2));
});

it('extracts an included part when the rate denominator exceeds int64', function (): void {
    $rate = Rate::bp(PHP_INT_MAX);

    expect(Money::ofMinor(100, 'JPY')->includedPart($rate, RoundingMode::Down)->amountMinor)->toBe(99)
        ->and(Money::ofMinor(PHP_INT_MAX, 'JPY')->includedPart($rate, RoundingMode::Down)->amountMinor)->toBe(PHP_INT_MAX - 10_000)
        ->and(Money::ofMinor(-100, 'JPY')->includedPart($rate, RoundingMode::Down)->amountMinor)->toBe(-99);
});
