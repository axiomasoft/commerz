<?php

declare(strict_types=1);

use Commerz\Errors\InvalidCommand;
use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Money\RoundingMode;
use Commerz\Pricing\PriceLists\Data\CreatePriceList;
use Commerz\Pricing\PriceLists\Data\PriceListFormula;
use Commerz\Pricing\PriceLists\Data\PriceListId;
use Commerz\Pricing\PriceLists\Data\RoundingRule;

it('applies psychological .99, .90, and multiples of 5 and 10 without floats', function (): void {
    $r99 = RoundingRule::ending99();
    $r90 = RoundingRule::ending90();
    $m5 = RoundingRule::multipleOf5();
    $m10 = RoundingRule::multipleOf10();

    expect($r99->apply(Money::of('10.00', 'EUR'))->toDecimal())->toBe('9.99')
        ->and($r99->apply(Money::of('19.80', 'EUR'))->toDecimal())->toBe('19.99')
        ->and($r99->apply(Money::of('20.49', 'EUR'))->toDecimal())->toBe('20.99')
        ->and($r99->apply(Money::of('19.99', 'EUR'))->toDecimal())->toBe('19.99')
        ->and($r99->apply(Money::of('0.30', 'EUR'))->toDecimal())->toBe('0.99')
        ->and($r99->apply(Money::zero('EUR'))->toDecimal())->toBe('0.00');

    expect(RoundingRule::ending99(RoundingMode::Ceiling)->apply(Money::of('20.05', 'EUR'))->toDecimal())->toBe('20.99');

    expect($r90->apply(Money::of('10.00', 'EUR'))->toDecimal())->toBe('9.90')
        ->and($r90->apply(Money::of('19.85', 'EUR'))->toDecimal())->toBe('19.90')
        ->and($r90->apply(Money::of('19.90', 'EUR'))->toDecimal())->toBe('19.90');

    expect($m5->apply(Money::of('17.30', 'EUR'))->toDecimal())->toBe('15.00')
        ->and($m5->apply(Money::of('18.30', 'EUR'))->toDecimal())->toBe('20.00')
        ->and($m5->apply(Money::of('18', 'JPY'))->toDecimal())->toBe('20')
        ->and($m10->apply(Money::of('24.00', 'EUR'))->toDecimal())->toBe('20.00')
        ->and($m10->apply(Money::of('26.00', 'EUR'))->toDecimal())->toBe('30.00')
        ->and(RoundingRule::minorMultiple(5)->apply(Money::of('12.03', 'EUR'))->toDecimal())->toBe('12.05');
});

it('rejects incompatible currency exponents and invalid rounding configurations', function (): void {
    expect(fn () => RoundingRule::ending99()->assertCompatibleWith(Currency::of('JPY')))
        ->toThrow(InvalidCommand::class)
        ->and(fn () => RoundingRule::ending90()->assertCompatibleWith(Currency::of('JPY')))
        ->toThrow(InvalidCommand::class)
        ->and(fn () => RoundingRule::minorMultiple(0))
        ->toThrow(InvalidCommand::class);

    $roundTrip = RoundingRule::fromArray(RoundingRule::ending99(RoundingMode::HalfEven)->toArray());
    expect($roundTrip)->toEqual(RoundingRule::ending99(RoundingMode::HalfEven));
});

it('evaluates margin_percent, fixed_delta, and fx_rate_with_spread formulas exactly across exponents', function (): void {
    $eur = Currency::of('EUR');
    $usd = Currency::of('USD');
    $jpy = Currency::of('JPY');
    $bhd = Currency::of('BHD');

    $markup = PriceListFormula::marginPercentDecimal('+25');
    $markdown = PriceListFormula::marginPercentDecimal('-15.5');
    expect($markup->evaluate(Money::of('80.00', $eur), $eur)->toDecimal())->toBe('100.00')
        ->and($markdown->evaluate(Money::of('100.00', $eur), $eur)->toDecimal())->toBe('84.50')
        ->and($markdown->evaluate(Money::of('100.00', $eur), $eur, RoundingRule::ending99())->toDecimal())->toBe('84.99');

    $deltaPlus = PriceListFormula::fixedDelta(500);
    $deltaMinus = PriceListFormula::fixedDelta(-2500);
    expect($deltaPlus->evaluate(Money::of('10.00', $eur), $eur)->toDecimal())->toBe('15.00')
        ->and($deltaMinus->evaluate(Money::of('10.00', $eur), $eur)->toDecimal())->toBe('0.00'); // clamped at zero

    // EUR (exp 2) -> USD (exp 2) with 1.10 FX + 1.00% spread (100 bp): 100.00 * 1.10 * 1.01 = 111.10 USD
    $fxUsd = PriceListFormula::fxRateWithSpread('1.10', spreadBp: 100);
    expect($fxUsd->evaluate(Money::of('100.00', $eur), $usd)->toDecimal())->toBe('111.10')
        ->and($fxUsd->evaluate(Money::of('100.00', $eur), $usd, RoundingRule::ending99())->toDecimal())->toBe('110.99');

    // USD (exp 2) -> JPY (exp 0) with 150.5 FX + 0 spread: 10.00 * 150.5 = 1505 JPY, rounded to multiple of 10 -> 1510 JPY
    $fxJpy = PriceListFormula::fxRateWithSpread('150.5', spreadBp: 0);
    expect($fxJpy->evaluate(Money::of('10.00', $usd), $jpy)->toDecimal())->toBe('1505')
        ->and($fxJpy->evaluate(Money::of('10.00', $usd), $jpy, RoundingRule::multipleOf10())->toDecimal())->toBe('1510');

    // EUR (exp 2) -> BHD (exp 3): 10.00 * 0.4085 = 4.085 BHD
    $fxBhd = PriceListFormula::fxRateWithSpread('0.4085');
    expect($fxBhd->evaluate(Money::of('10.00', $eur), $bhd)->toDecimal())->toBe('4.085');

    // Serialization round-trip
    foreach ([$markup, $deltaPlus, $fxUsd] as $formula) {
        expect(PriceListFormula::fromArray($formula->toArray()))->toEqual($formula);
    }

    // Validation
    expect(fn () => $markup->evaluate(Money::of('10.00', $eur), $usd))->toThrow(InvalidCommand::class)
        ->and(fn () => PriceListFormula::fxRateWithSpread('0'))->toThrow(InvalidCommand::class)
        ->and(fn () => new CreatePriceList('child', 'Child', $eur, formula: $markup))->toThrow(InvalidCommand::class)
        ->and(new CreatePriceList('child', 'Child', $eur, parentPriceListId: PriceListId::generate(), formula: $markup)->formula)->toEqual($markup);
});
