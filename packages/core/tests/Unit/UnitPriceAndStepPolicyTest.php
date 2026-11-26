<?php

declare(strict_types=1);

use Commerz\Errors\InvalidCommand;
use Commerz\Identity\SellableRef;
use Commerz\Measures\Quantity;
use Commerz\Measures\QuantityStepPolicy;
use Commerz\Measures\UnitPrice;
use Commerz\Money\Money;
use Commerz\Money\RoundingMode;
use Commerz\Ports\Sellables\Data\SellableSnapshot;
use Commerz\Ports\Sellables\Enums\SellableKind;

it('validates quantities against a weighted QuantityStepPolicy', function (): void {
    $policy = QuantityStepPolicy::of(500, 100, 5_000, 'g');

    expect($policy->min)->toBe(500)
        ->and($policy->step)->toBe(100)
        ->and($policy->max)->toBe(5_000)
        ->and($policy->unit)->toBe('g')
        ->and($policy->maxValid())->toBe(5_000)
        ->and($policy->isValid(500))->toBeTrue()
        ->and($policy->isValid(1_500))->toBeTrue()
        ->and($policy->isValid(5_000))->toBeTrue()
        ->and($policy->isValid(Quantity::of(1_500, 'g')))->toBeTrue()
        ->and($policy->isValid(400))->toBeFalse()
        ->and($policy->isValid(1_550))->toBeFalse()
        ->and($policy->isValid(5_100))->toBeFalse()
        ->and($policy->isValid(0))->toBeFalse()
        ->and($policy->isValid(-100))->toBeFalse()
        ->and($policy->isValid(Quantity::of(1_500, 'ml')))->toBeFalse();

    $policy->assertValid(1_500);
    $policy->assertValid(Quantity::of(1_500, 'g'));

    expect(fn () => $policy->assertValid(400))->toThrow(fn (InvalidCommand $e) => expect($e->code())->toBe('measures.invalid_quantity_step'))
        ->and(fn () => $policy->assertValid(1_550))->toThrow(fn (InvalidCommand $e) => expect($e->code())->toBe('measures.invalid_quantity_step'))
        ->and(fn () => $policy->assertValid(5_100))->toThrow(fn (InvalidCommand $e) => expect($e->code())->toBe('measures.invalid_quantity_step'))
        ->and(fn () => $policy->assertValid(Quantity::of(1_500, 'mm')))->toThrow(fn (InvalidCommand $e) => expect($e->code())->toBe('measures.unit_mismatch'));
});

it('supports non-multiple minimums and non-aligned maximum ceilings', function (): void {
    $policy = new QuantityStepPolicy(250, 100, 500, 'g');

    expect($policy->maxValid())->toBe(450)
        ->and($policy->isValid(250))->toBeTrue()
        ->and($policy->isValid(350))->toBeTrue()
        ->and($policy->isValid(450))->toBeTrue()
        ->and($policy->isValid(300))->toBeFalse()
        ->and($policy->isValid(500))->toBeFalse()
        ->and($policy->normalize(0))->toBe(250)
        ->and($policy->normalize(200))->toBe(250)
        ->and($policy->normalize(440, RoundingMode::Up))->toBe(450)
        ->and($policy->normalize(480, RoundingMode::Up))->toBe(450)
        ->and($policy->normalize(999))->toBe(450);

    $unbounded = QuantityStepPolicy::pieces(2, 2);

    expect($unbounded->maxValid())->toBeNull()
        ->and($unbounded->unit)->toBe('pcs')
        ->and($unbounded->equals(new QuantityStepPolicy(2, 2, null, 'pcs')))->toBeTrue()
        ->and($unbounded->equals(new QuantityStepPolicy(2, 2, 10, 'pcs')))->toBeFalse();
});

it('normalizes quantities using every explicit RoundingMode', function (RoundingMode $mode, int $requested, int $expected): void {
    $policy = new QuantityStepPolicy(100, 100, null, 'g');

    expect($policy->normalize($requested, $mode))->toBe($expected)
        ->and($policy->normalizeQuantity(Quantity::of($requested, 'g'), $mode))->toEqual(Quantity::of($expected, 'g'));
})->with([
    'exact step' => [RoundingMode::HalfUp, 1_500, 1_500],
    'below min clamps to min' => [RoundingMode::Down, 40, 100],
    'zero clamps to min' => [RoundingMode::HalfUp, 0, 100],
    'half up on .5' => [RoundingMode::HalfUp, 150, 200],
    'half down on .5' => [RoundingMode::HalfDown, 150, 100],
    'half even to even 0 steps' => [RoundingMode::HalfEven, 150, 100],
    'half even to even 2 steps' => [RoundingMode::HalfEven, 250, 300],
    'up rounds small fraction up' => [RoundingMode::Up, 101, 200],
    'ceiling rounds small fraction up' => [RoundingMode::Ceiling, 101, 200],
    'down rounds large fraction down' => [RoundingMode::Down, 199, 100],
    'floor rounds large fraction down' => [RoundingMode::Floor, 199, 100],
]);

it('rejects invalid QuantityStepPolicy configurations and normalization edge cases', function (): void {
    expect(fn () => new QuantityStepPolicy(0, 100, null, 'g'))->toThrow(InvalidCommand::class, 'min')
        ->and(fn () => new QuantityStepPolicy(-5, 100, null, 'g'))->toThrow(InvalidCommand::class, 'min')
        ->and(fn () => new QuantityStepPolicy(100, 0, null, 'g'))->toThrow(InvalidCommand::class, 'step')
        ->and(fn () => new QuantityStepPolicy(100, -10, null, 'g'))->toThrow(InvalidCommand::class, 'step')
        ->and(fn () => new QuantityStepPolicy(500, 100, 400, 'g'))->toThrow(InvalidCommand::class, 'max')
        ->and(fn () => new QuantityStepPolicy(100, 100, null, 'KG'))->toThrow(InvalidCommand::class, 'unit');

    $policy = new QuantityStepPolicy(100, 100, null, 'g');

    expect(fn () => $policy->normalize(-1))->toThrow(fn (InvalidCommand $e) => expect($e->code())->toBe('measures.invalid_quantity'))
        ->and(fn () => $policy->normalizeQuantity(Quantity::of(150, 'ml')))->toThrow(fn (InvalidCommand $e) => expect($e->code())->toBe('measures.unit_mismatch'))
        ->and(fn () => $policy->normalize(PHP_INT_MAX, RoundingMode::Up))->toThrow(fn (InvalidCommand $e) => expect($e->code())->toBe('measures.overflow'))
        ->and($policy->normalize(PHP_INT_MAX, RoundingMode::Down))->toBe(PHP_INT_MAX - ((PHP_INT_MAX - 100) % 100));
});

it('calculates exact UnitPrice totals for weighted, measured, and piece goods', function (): void {
    $perKg = UnitPrice::perKilogram(Money::of('250.00', 'RUB'));
    $perMeter = UnitPrice::perMeter(Money::of('120.00', 'RUB'));
    $perLiter = UnitPrice::perLiter(Money::of('80.00', 'RUB'));
    $perPiece = UnitPrice::perPiece(Money::of('19.90', 'RUB'));

    expect($perKg->calculate(1_500)->toDecimal())->toBe('375.00')
        ->and($perKg->calculate(Quantity::of(1_500, 'g'))->toDecimal())->toBe('375.00')
        ->and($perKg->calculate(0)->isZero())->toBeTrue()
        ->and($perMeter->calculate(Quantity::of(2_500, 'mm'))->toDecimal())->toBe('300.00')
        ->and($perLiter->calculate(Quantity::of(750, 'ml'))->toDecimal())->toBe('60.00')
        ->and($perPiece->calculate(Quantity::pieces(3))->toDecimal())->toBe('59.70')
        ->and($perKg->withAmount(Money::of('300.00', 'USD'))->equals(UnitPrice::of(Money::of('300.00', 'USD'), 1_000, 'g')))->toBeTrue();
});

it('applies explicit RoundingMode on exact halves and repeating ratios in UnitPrice', function (
    string $price,
    int $perUnits,
    int $quantity,
    RoundingMode $mode,
    string $expected,
): void {
    $unitPrice = UnitPrice::of(Money::of($price, 'RUB'), $perUnits, 'g');

    expect($unitPrice->calculate($quantity, $mode)->toDecimal())->toBe($expected);
})->with([
    '1.5 kg at 333.33 half up' => ['333.33', 1_000, 1_500, RoundingMode::HalfUp, '500.00'],
    '1.5 kg at 333.33 half down' => ['333.33', 1_000, 1_500, RoundingMode::HalfDown, '499.99'],
    '1.5 kg at 333.33 half even' => ['333.33', 1_000, 1_500, RoundingMode::HalfEven, '500.00'],
    '500.5 minor half up' => ['10.01', 1_000, 500, RoundingMode::HalfUp, '5.01'],
    '500.5 minor half down' => ['10.01', 1_000, 500, RoundingMode::HalfDown, '5.00'],
    '500.5 minor half even to even' => ['10.01', 1_000, 500, RoundingMode::HalfEven, '5.00'],
    '501.5 minor half even to even' => ['10.03', 1_000, 500, RoundingMode::HalfEven, '5.02'],
    '500.5 minor up' => ['10.01', 1_000, 500, RoundingMode::Up, '5.01'],
    '500.5 minor ceiling' => ['10.01', 1_000, 500, RoundingMode::Ceiling, '5.01'],
    '500.5 minor down' => ['10.01', 1_000, 500, RoundingMode::Down, '5.00'],
    '500.5 minor floor' => ['10.01', 1_000, 500, RoundingMode::Floor, '5.00'],
    'repeating third down' => ['100.00', 3, 1, RoundingMode::HalfUp, '33.33'],
    'repeating third up' => ['100.00', 3, 1, RoundingMode::Up, '33.34'],
    'repeating two thirds half up' => ['100.00', 3, 2, RoundingMode::HalfUp, '66.67'],
    'repeating two thirds down' => ['100.00', 3, 2, RoundingMode::Down, '66.66'],
    'zero amount' => ['0.00', 1_000, 1_500, RoundingMode::HalfUp, '0.00'],
]);

it('avoids intermediate int64 overflow and rejects final overflow or invalid UnitPrice inputs', function (): void {
    $large = UnitPrice::of(Money::ofMinor(10_000_000_000_000, 'RUB'), 10_000_000_000, 'g');

    expect($large->calculate(10_000_000_000, RoundingMode::HalfUp)->amountMinor)->toBe(10_000_000_000_000);

    $overflow = UnitPrice::of(Money::ofMinor(PHP_INT_MAX, 'RUB'), 1, 'g');

    expect(fn () => $overflow->calculate(2, RoundingMode::HalfUp))
        ->toThrow(fn (InvalidCommand $e) => expect($e->code())->toBe('money.overflow'))
        ->and(fn () => UnitPrice::perKilogram(Money::of('-1.00', 'RUB')))->toThrow(InvalidCommand::class, 'amount')
        ->and(fn () => UnitPrice::of(Money::of('10.00', 'RUB'), 0, 'g'))->toThrow(InvalidCommand::class, 'perUnits')
        ->and(fn () => UnitPrice::of(Money::of('10.00', 'RUB'), -10, 'g'))->toThrow(InvalidCommand::class, 'perUnits')
        ->and(fn () => UnitPrice::of(Money::of('10.00', 'RUB'), 1_000, 'Bad Unit'))->toThrow(InvalidCommand::class, 'unit')
        ->and(fn () => UnitPrice::perKilogram(Money::of('10.00', 'RUB'))->calculate(-1))->toThrow(InvalidCommand::class, 'quantity')
        ->and(fn () => UnitPrice::perKilogram(Money::of('10.00', 'RUB'))->calculate(Quantity::of(100, 'ml')))
        ->toThrow(fn (InvalidCommand $e) => expect($e->code())->toBe('measures.unit_mismatch'));
});

it('validates optional QuantityStepPolicy and UnitPrice on SellableSnapshot', function (): void {
    $ref = SellableRef::of('fake', 'apples');
    $policy = QuantityStepPolicy::of(500, 100, 5_000, 'g');
    $unitPrice = UnitPrice::perKilogram(Money::of('250.00', 'RUB'));

    $defaultSnapshot = new SellableSnapshot($ref, 'SKU-1', 'Default', SellableKind::Physical, true);
    $weighted = new SellableSnapshot($ref, 'SKU-2', 'Apples', SellableKind::Physical, true, quantityStepPolicy: $policy, unitPrice: $unitPrice);

    expect($defaultSnapshot->quantityStepPolicy)->toBeNull()
        ->and($defaultSnapshot->unitPrice)->toBeNull()
        ->and($weighted->quantityStepPolicy)->toEqual($policy)
        ->and($weighted->unitPrice)->toEqual($unitPrice);

    expect(fn () => new SellableSnapshot(
        $ref,
        'SKU-3',
        'Bad Unit',
        SellableKind::Physical,
        true,
        quantityStepPolicy: $policy,
        unitPrice: UnitPrice::perMeter(Money::of('100.00', 'RUB')),
    ))->toThrow(InvalidCommand::class, 'quantityStepPolicy')
        ->and(fn () => new SellableSnapshot(
            $ref,
            'SKU-4',
            'Bad Currency',
            SellableKind::Physical,
            true,
            basePrice: Money::of('250.00', 'USD'),
            unitPrice: $unitPrice,
        ))->toThrow(InvalidCommand::class, 'currency');
});
