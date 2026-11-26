<?php

declare(strict_types=1);

use Commerz\Context\CommerceContext;
use Commerz\Errors\InvalidCommand;
use Commerz\Identity\SellableRef;
use Commerz\Measures\QuantityStepPolicy;
use Commerz\Measures\UnitPrice;
use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Money\RoundingMode;
use Commerz\Ports\Sellables\Data\SellableSnapshot;
use Commerz\Ports\Sellables\Enums\SellableKind;
use Commerz\Quotes\Contracts\QuotePipeline;
use Commerz\Quotes\Data\QuoteInput;
use Commerz\Quotes\Data\QuoteLine;
use Commerz\Quotes\Data\QuoteLineInput;
use Commerz\Quotes\Exceptions\QuoteFailed;
use Commerz\Stores\Data\ChannelId;
use Commerz\Testkit\Commerce;

function wgContext(): CommerceContext
{
    return CommerceContext::for(
        ChannelId::fromString('0190a000-0000-7000-8000-000000000001'),
        Currency::of('RUB'),
        'ru',
        new DateTimeImmutable('2026-10-08T12:00:00Z'),
    );
}

function wgWeightedSnapshot(
    string $id,
    string $pricePerKg,
    string $currency = 'RUB',
    int $min = 500,
    int $step = 100,
    ?int $max = 5_000,
): SellableSnapshot {
    return new SellableSnapshot(
        SellableRef::of('fake', $id),
        'SKU-'.strtoupper($id),
        "Weighted {$id}",
        SellableKind::Physical,
        true,
        quantityStepPolicy: QuantityStepPolicy::of($min, $step, $max, 'g'),
        unitPrice: UnitPrice::perKilogram(Money::of($pricePerKg, $currency)),
    );
}

beforeEach(function (): void {
    Commerce::fake();
});

it('quotes weighted goods using exact UnitPrice calculation and preserves pricePerUnit', function (): void {
    $apples = wgWeightedSnapshot('apples', '250.00');
    $input = new QuoteInput(wgContext(), [
        new QuoteLineInput('line-1', $apples, 1_500),
    ]);

    $result = app(QuotePipeline::class)->run($input);

    expect($result->lines)->toHaveCount(1)
        ->and($result->lines[0]->quantity)->toBe(1_500)
        ->and($result->lines[0]->unitPrice->toDecimal())->toBe('250.00')
        ->and($result->lines[0]->pricePerUnit)->toEqual(UnitPrice::perKilogram(Money::of('250.00', 'RUB')))
        ->and($result->lines[0]->subtotal->toDecimal())->toBe('375.00')
        ->and($result->lines[0]->total->toDecimal())->toBe('375.00')
        ->and($result->totals->subtotal->toDecimal())->toBe('375.00')
        ->and($result->total->toDecimal())->toBe('375.00');
});

it('applies PriceResolver overrides to weighted sellables while keeping perUnits and unit', function (): void {
    $commerce = Commerce::fake();
    $cheese = wgWeightedSnapshot('cheese', '800.00');
    $commerce->prices->set($cheese->ref, Money::of('640.00', 'RUB'), Money::of('800.00', 'RUB'));

    $result = app(QuotePipeline::class)->run(new QuoteInput(wgContext(), [
        new QuoteLineInput('cheese-1', $cheese, 700),
    ]));

    expect($result->lines[0]->unitPrice->toDecimal())->toBe('640.00')
        ->and($result->lines[0]->compareAt?->toDecimal())->toBe('800.00')
        ->and($result->lines[0]->priceSource)->toBe('fake')
        ->and($result->lines[0]->pricePerUnit)->toEqual(UnitPrice::perKilogram(Money::of('640.00', 'RUB')))
        ->and($result->lines[0]->subtotal->toDecimal())->toBe('448.00')
        ->and($result->total->toDecimal())->toBe('448.00');
});

it('respects explicit RoundingMode on QuoteInput and includes it in the quote fingerprint', function (): void {
    $truffles = wgWeightedSnapshot('truffles', '333.33');
    $line = new QuoteLineInput('truffles-1', $truffles, 1_500);

    $halfUp = app(QuotePipeline::class)->run(new QuoteInput(wgContext(), [$line], rounding: RoundingMode::HalfUp));
    $halfDown = app(QuotePipeline::class)->run(new QuoteInput(wgContext(), [$line], rounding: RoundingMode::HalfDown));

    expect($halfUp->total->toDecimal())->toBe('500.00')
        ->and($halfDown->total->toDecimal())->toBe('499.99')
        ->and($halfUp->fingerprint)->not->toBe($halfDown->fingerprint);
});

it('enforces QuantityStepPolicy in QuoteLineInput and normalizes via QuoteLineInput::normalized', function (): void {
    $apples = wgWeightedSnapshot('apples', '250.00', min: 500, step: 100, max: 5_000);

    expect(fn () => new QuoteLineInput('a', $apples, 400))
        ->toThrow(fn (InvalidCommand $e) => expect($e->code())->toBe('measures.invalid_quantity_step'))
        ->and(fn () => new QuoteLineInput('a', $apples, 1_550))
        ->toThrow(fn (InvalidCommand $e) => expect($e->code())->toBe('measures.invalid_quantity_step'))
        ->and(fn () => new QuoteLineInput('a', $apples, 5_100))
        ->toThrow(fn (InvalidCommand $e) => expect($e->code())->toBe('measures.invalid_quantity_step'));

    $normalizedUp = QuoteLineInput::normalized('a', $apples, 1_550, RoundingMode::HalfUp);
    $normalizedDown = QuoteLineInput::normalized('a', $apples, 1_550, RoundingMode::HalfDown);

    expect($normalizedUp->quantity)->toBe(1_600)
        ->and($normalizedDown->quantity)->toBe(1_500);
});

it('validates QuoteLine invariants for pricePerUnit and QuantityStepPolicy', function (): void {
    $apples = wgWeightedSnapshot('apples', '250.00');

    expect(fn () => new QuoteLine('a', $apples, 1_550, Money::of('250.00', 'RUB'), null, false, 'test'))
        ->toThrow(fn (InvalidCommand $e) => expect($e->code())->toBe('measures.invalid_quantity_step'))
        ->and(fn () => new QuoteLine(
            'a',
            $apples,
            1_500,
            Money::of('250.00', 'RUB'),
            null,
            false,
            'test',
            UnitPrice::perKilogram(Money::of('200.00', 'RUB')),
        ))->toThrow(InvalidCommand::class, 'pricePerUnit')
        ->and(fn () => new QuoteLine(
            'a',
            $apples,
            1_500,
            Money::of('250.00', 'RUB'),
            null,
            false,
            'test',
            UnitPrice::perMeter(Money::of('250.00', 'RUB')),
        ))->toThrow(InvalidCommand::class, 'quantityStepPolicy');

    $derived = new QuoteLine('a', $apples, 1_500, Money::of('200.00', 'RUB'), null, false, 'test');

    expect($derived->pricePerUnit)->toEqual(UnitPrice::perKilogram(Money::of('200.00', 'RUB')))
        ->and($derived->subtotal()->toDecimal())->toBe('300.00');
});

it('fails when weighted sellable unitPrice is in a foreign currency without a price list override', function (): void {
    $imported = wgWeightedSnapshot('imported', '10.00', currency: 'USD');
    $input = new QuoteInput(wgContext(), [new QuoteLineInput('imp-1', $imported, 1_500)]);

    expect(fn () => app(QuotePipeline::class)->run($input))
        ->toThrow(fn (QuoteFailed $e) => expect($e->context()['cause'])->toBe('prices.price_not_found'));

    Commerce::fake()->prices->set($imported->ref, Money::of('900.00', 'RUB'));

    expect(app(QuotePipeline::class)->run($input)->total->toDecimal())->toBe('1350.00');
});
