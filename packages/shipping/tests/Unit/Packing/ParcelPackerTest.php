<?php

declare(strict_types=1);

use Commerz\Errors\InvalidCommand;
use Commerz\Money\Money;
use Commerz\Shipping\Packing\Data\PackableItem;
use Commerz\Shipping\Packing\Data\PackingConstraints;
use Commerz\Shipping\Packing\Data\PackingRequest;
use Commerz\Shipping\Packing\Exceptions\UnpackableItem;
use Commerz\Shipping\Packing\Internal\WeightAndVolumeParcelPacker;

it('splits a heavy order into 2+ parcels by physical weight while preserving exact Money minor units', function (): void {
    $packer = new WeightAndVolumeParcelPacker;

    // 3 units weighing 14 kg each (42 kg total) under a 30 kg parcel limit -> 2 parcels (28 kg + 14 kg)
    // Line value 100.00 EUR across 3 units = 33.34 + 33.33 + 33.33 -> 66.67 EUR + 33.33 EUR = 100.00 EUR exact
    $result = $packer->pack(new PackingRequest(
        items: [
            new PackableItem(
                itemId: 'line-kettlebell',
                quantity: 3,
                unitWeightGrams: 14_000,
                totalValue: Money::of('100.00', 'EUR'),
                lengthMm: 250,
                widthMm: 250,
                heightMm: 300,
            ),
        ],
        constraints: new PackingConstraints(maxWeightGrams: 30_000),
    ));

    expect($result->parcels)->toHaveCount(2)
        ->and($result->parcels[0]->sequence)->toBe(1)
        ->and($result->parcels[0]->physicalWeightGrams)->toBe(28_000)
        ->and($result->parcels[0]->items[0]->quantity)->toBe(2)
        ->and($result->parcels[0]->parcel->value->toDecimal())->toBe('66.67')
        ->and($result->parcels[1]->sequence)->toBe(2)
        ->and($result->parcels[1]->physicalWeightGrams)->toBe(14_000)
        ->and($result->parcels[1]->items[0]->quantity)->toBe(1)
        ->and($result->parcels[1]->parcel->value->toDecimal())->toBe('33.33')
        ->and($result->totalPhysicalWeightGrams())->toBe(42_000)
        ->and($result->totalValue()->equals(Money::of('100.00', 'EUR')))->toBeTrue();
});

it('splits bulky lightweight orders by volumetric weight using exact brick/math integer division', function (): void {
    $packer = new WeightAndVolumeParcelPacker;

    // Each duvet weighs only 2 kg physically, but measures 600 x 500 x 400 mm = 120,000,000 mm³
    // Volumetric weight at divisor 5000 = 120,000,000 / 5000 = 24,000 g (24 kg) per unit!
    // Two units = 48 kg volumetric > 30 kg maxWeightGrams -> MUST split into 2 parcels!
    $duvet = PackableItem::ofUnitPrice(
        itemId: 'line-duvet',
        quantity: 2,
        unitWeightGrams: 2_000,
        unitPrice: Money::of('75.00', 'EUR'),
        lengthMm: 600,
        widthMm: 500,
        heightMm: 400,
    );

    expect($duvet->unitVolumetricWeightGrams(5_000))->toBe(24_000);

    $result = $packer->pack(new PackingRequest(
        items: [$duvet],
        constraints: new PackingConstraints(maxWeightGrams: 30_000, volumetricDivisor: 5_000),
    ));

    expect($result->parcels)->toHaveCount(2)
        ->and($result->parcels[0]->physicalWeightGrams)->toBe(2_000)
        ->and($result->parcels[0]->volumetricWeightGrams)->toBe(24_000)
        ->and($result->parcels[0]->chargeableWeightGrams)->toBe(24_000)
        ->and($result->parcels[1]->chargeableWeightGrams)->toBe(24_000)
        ->and($result->totalValue()->toDecimal())->toBe('150.00');
});

it('packs mixed multi-line orders with tare weight and box dimension limits, and rejects unpackable single units', function (): void {
    $packer = new WeightAndVolumeParcelPacker;

    $result = $packer->pack(new PackingRequest(
        items: [
            PackableItem::ofUnitPrice('heavy-a', 2, 12_000, Money::of('50.00', 'USD'), 400, 300, 200),
            PackableItem::ofUnitPrice('light-b', 3, 2_000, Money::of('10.00', 'USD'), 200, 150, 100),
        ],
        constraints: new PackingConstraints(
            maxWeightGrams: 20_000,
            tareWeightGrams: 500,
            maxLengthMm: 600,
            maxWidthMm: 400,
            maxHeightMm: 450,
        ),
    ));

    // Each 12 kg item + 0.5 kg tare = 12.5 kg, so the two 12 kg units cannot share a 20 kg box -> at least 2 parcels,
    // and the three 2 kg units fit alongside the first 12.5 kg box (12.5 + 6 = 18.5 kg <= 20 kg)!
    expect(count($result->parcels))->toBeGreaterThanOrEqual(2)
        ->and($result->totalValue()->toDecimal())->toBe('130.00');

    // Single unit heavier than maxWeightGrams throws UnpackableItem
    expect(fn () => $packer->pack(new PackingRequest(
        items: [PackableItem::ofUnitPrice('anvil', 1, 35_000, Money::of('200.00', 'USD'))],
        constraints: new PackingConstraints(maxWeightGrams: 30_000),
    )))->toThrow(UnpackableItem::class, 'anvil');

    // Partial dimensions rejected on PackableItem
    expect(fn () => new PackableItem('bad-dim', 1, 500, Money::of('10.00', 'USD'), lengthMm: 200, widthMm: 100))
        ->toThrow(InvalidCommand::class, 'dimensions');
});
