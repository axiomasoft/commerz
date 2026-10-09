<?php

declare(strict_types=1);

use Commerz\Context\CommerceContext;
use Commerz\Errors\ErrorCategory;
use Commerz\Identity\SellableRef;
use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Money\Rate;
use Commerz\Pricing\Calculation\Data\TaxableItem;
use Commerz\Pricing\Calculation\Data\TaxAmount;
use Commerz\Pricing\Calculation\Data\TaxRequest;
use Commerz\Pricing\Calculation\Data\TaxResult;
use Commerz\Pricing\Calculation\Exceptions\InvalidTaxResult;
use Commerz\Pricing\Calculation\Internal\TaxResultValidator;
use Commerz\Quotes\Enums\AdjustmentTarget;
use Commerz\Stores\Data\ChannelId;

function taxAmount(string $amount, string $lineId = '1', string $code = 'vat', string $currency = 'RUB', AdjustmentTarget $target = AdjustmentTarget::Line): TaxAmount
{
    return new TaxAmount($target, $target === AdjustmentTarget::Line ? $lineId : null, $code, Rate::bp(2200), Money::of($amount, $currency));
}

function validate(TaxRequest $request, TaxAmount ...$amounts): void
{
    (new TaxResultValidator)->assertValid('acme.engine', $request, new TaxResult(array_values($amounts)));
}

function validatorLine(string $lineId, string $base, bool $included): TaxableItem
{
    return new TaxableItem(AdjustmentTarget::Line, $lineId, SellableRef::of('variant', $lineId), 'standard', 1, Money::of($base, 'RUB'), $included);
}

function requestOf(bool $included = false, bool $withDelivery = true): TaxRequest
{
    $items = [validatorLine('1', '100.00', $included), validatorLine('2', '50.00', $included)];

    if ($withDelivery) {
        $items[] = new TaxableItem(AdjustmentTarget::Delivery, null, null, 'standard', 1, Money::of('300.00', 'RUB'), false);
    }

    return new TaxRequest(CommerceContext::for(ChannelId::generate(), Currency::of('RUB')), null, $items);
}

it('accepts an empty result, a result for some of the items and several taxes on one item', function (): void {
    expect(fn () => validate(requestOf()))->not->toThrow(InvalidTaxResult::class)
        ->and(fn () => validate(requestOf(), taxAmount('22.00')))->not->toThrow(InvalidTaxResult::class)
        ->and(fn () => validate(requestOf(), taxAmount('5.00', code: 'gst'), taxAmount('7.00', code: 'pst'), taxAmount('11.00', '2'), taxAmount('66.00', target: AdjustmentTarget::Delivery)))
        ->not->toThrow(InvalidTaxResult::class);
});

it('accepts zero taxes and an excluded tax above its base', function (): void {
    expect(fn () => validate(requestOf(), taxAmount('0.00'), taxAmount('999.00', '2')))->not->toThrow(InvalidTaxResult::class);
});

it('accepts an included tax up to the whole base', function (): void {
    expect(fn () => validate(requestOf(included: true), taxAmount('22.00')))->not->toThrow(InvalidTaxResult::class)
        ->and(fn () => validate(requestOf(included: true), taxAmount('60.00', code: 'gst'), taxAmount('40.00', code: 'pst'))) // exactly the base
        ->not->toThrow(InvalidTaxResult::class);
});

it('refuses a tax for an item that was not asked about', function (): void {
    expect(fn () => validate(requestOf(), taxAmount('1.00', '3')))
        ->toThrow(fn (InvalidTaxResult $e) => expect($e->code())->toBe('pricing.invalid_tax_result')->and($e->calculator)->toBe('acme.engine')->and($e->reason)->toContain('unknown item'))
        ->and(fn () => validate(requestOf(withDelivery: false), taxAmount('1.00', target: AdjustmentTarget::Delivery)))
        ->toThrow(fn (InvalidTaxResult $e) => expect($e->reason)->toContain('delivery'));
});

it('refuses a tax in another currency', function (): void {
    expect(fn () => validate(requestOf(), taxAmount('22.00', currency: 'EUR')))
        ->toThrow(fn (InvalidTaxResult $e) => expect($e->reason)->toContain('EUR'));
});

it('refuses the same tax twice on one item', function (): void {
    expect(fn () => validate(requestOf(), taxAmount('1.00'), taxAmount('2.00')))
        ->toThrow(fn (InvalidTaxResult $e) => expect($e->reason)->toContain('appears twice'));
});

it('refuses included taxes that exceed the base of the item', function (): void {
    expect(fn () => validate(requestOf(included: true), taxAmount('100.01')))
        ->toThrow(fn (InvalidTaxResult $e) => expect($e->reason)->toContain('exceed'))
        ->and(fn () => validate(requestOf(included: true), taxAmount('60.00', code: 'gst'), taxAmount('40.01', code: 'pst')))
        ->toThrow(InvalidTaxResult::class);
});

it('refuses taxes whose sum overflows', function (): void {
    $max = Money::ofMinor(PHP_INT_MAX, 'RUB');
    $amounts = [
        new TaxAmount(AdjustmentTarget::Line, '1', 'gst', Rate::bp(1), $max),
        new TaxAmount(AdjustmentTarget::Line, '1', 'pst', Rate::bp(1), Money::ofMinor(1, 'RUB')),
    ];

    expect(fn () => (new TaxResultValidator)->assertValid('acme.engine', requestOf(), new TaxResult($amounts)))
        ->toThrow(fn (InvalidTaxResult $e) => expect($e->reason)->toContain('overflow'));
});

it('is an integration error', function (): void {
    expect(new InvalidTaxResult('x', 'because')->category())->toBe(ErrorCategory::Integration)
        ->and(new InvalidTaxResult('x', 'because')->context())->toBe(['calculator' => 'x', 'reason' => 'because']);
});
