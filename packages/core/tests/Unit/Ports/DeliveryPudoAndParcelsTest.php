<?php

declare(strict_types=1);

use Commerz\Context\CommerceContext;
use Commerz\Errors\InvalidCommand;
use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Ports\Delivery\Data\Address;
use Commerz\Ports\Delivery\Data\BookedParcel;
use Commerz\Ports\Delivery\Data\BookedShipment;
use Commerz\Ports\Delivery\Data\BookShipment;
use Commerz\Ports\Delivery\Data\Coordinates;
use Commerz\Ports\Delivery\Data\DeliveryOption;
use Commerz\Ports\Delivery\Data\Parcel;
use Commerz\Ports\Delivery\Data\PickupPoint;
use Commerz\Ports\Delivery\Data\PickupPointDestination;
use Commerz\Ports\Delivery\Data\PickupPointLimits;
use Commerz\Ports\Delivery\Data\PickupPointQuery;
use Commerz\Ports\Delivery\Enums\DeliveryMode;
use Commerz\Stores\Data\ChannelId;

it('supports DeliveryMode on DeliveryOption and distinguishes pickup modes from home shipping', function (): void {
    $shipping = new DeliveryOption('courier', 'Courier', Money::of('300.00', 'RUB'), 'cdek');
    $pudo = new DeliveryOption('cdek_locker', 'CDEK Locker', Money::of('150.00', 'RUB'), 'cdek', 2, [], DeliveryMode::PickupPoint);
    $bopis = new DeliveryOption('store_pickup', 'Store Pickup', Money::zero('RUB'), 'manual', 0, [], DeliveryMode::LocalPickup);

    expect($shipping->mode)->toBe(DeliveryMode::Shipping)
        ->and($shipping->mode->requiresPickupPoint())->toBeFalse()
        ->and($pudo->mode)->toBe(DeliveryMode::PickupPoint)
        ->and($pudo->mode->requiresPickupPoint())->toBeTrue()
        ->and($bopis->mode)->toBe(DeliveryMode::LocalPickup)
        ->and($bopis->mode->requiresPickupPoint())->toBeTrue();
});

it('parses and measures geographic Coordinates in integer microdegrees without floats', function (): void {
    $redSquare = Coordinates::fromDecimal('55.753930', '37.620795');
    $nearby = Coordinates::fromDecimal('55.762930', '37.620795'); // ~1 km north

    expect($redSquare->latitudeMicrodeg)->toBe(55_753_930)
        ->and($redSquare->longitudeMicrodeg)->toBe(37_620_795)
        ->and($redSquare->latitudeDecimal())->toBe('55.753930')
        ->and($redSquare->longitudeDecimal())->toBe('37.620795')
        ->and($redSquare->distanceMetersTo($redSquare))->toBe(0)
        ->and($redSquare->distanceMetersTo($nearby))->toBeBetween(995, 1010)
        ->and(fn () => new Coordinates(90_000_001, 0))->toThrow(InvalidCommand::class, 'latitudeMicrodeg')
        ->and(fn () => new Coordinates(0, -180_000_001))->toThrow(InvalidCommand::class, 'longitudeMicrodeg')
        ->and(fn () => Coordinates::fromDecimal('not-a-number', '37.6'))->toThrow(InvalidCommand::class, 'latitude');
});

it('checks locker 3D dimensions with orthogonal rotation and rejects oversized or overweight items', function (): void {
    $smallLocker = new PickupPointLimits(
        maxWeightGrams: 10_000,
        maxLengthMm: 600,
        maxWidthMm: 400,
        maxHeightMm: 350,
    );

    $compactBox = new Parcel(2_500, Money::of('50.00', 'EUR'), 300, 250, 200);
    $rotatedBox = new Parcel(4_000, Money::of('80.00', 'EUR'), 380, 580, 320); // fits when rotated (320<=350, 380<=400, 580<=600)
    $oversizedLength = new Parcel(3_000, Money::of('90.00', 'EUR'), 750, 300, 200); // 750 > 600
    $overweight = new Parcel(12_000, Money::of('90.00', 'EUR'), 200, 200, 200); // 12,000 > 10,000

    expect($smallLocker->fitsParcel($compactBox))->toBeTrue()
        ->and($smallLocker->fitsParcel($rotatedBox, allowRotation: true))->toBeTrue()
        ->and($smallLocker->fitsParcel($rotatedBox, allowRotation: false))->toBeFalse()
        ->and($smallLocker->fitsParcel($oversizedLength))->toBeFalse()
        ->and($smallLocker->fitsParcel($overweight))->toBeFalse()
        ->and($smallLocker->fitsAll(new Parcel(3_000, Money::of('90.00', 'EUR')), [$oversizedLength]))->toBeFalse();
});

it('keeps PickupPointDestination separate from billing address and validates pickup queries', function (): void {
    $billingAddress = new Address('RU', 'RU-MOW', 'Moscow', '119002', ['Arbat 10, apt 5'], 'Ivan Petrov', '+79991112233');
    $lockerAddress = new Address('RU', 'RU-MOW', 'Moscow', '101000', ['Tverskaya 7, Locker #12']);

    $point = new PickupPoint(
        id: 'msk-locker-12',
        provider: 'cdek',
        name: 'CDEK Locker Tverskaya',
        mode: DeliveryMode::PickupPoint,
        address: $lockerAddress,
        coordinates: Coordinates::fromDecimal('55.757500', '37.613000'),
        limits: new PickupPointLimits(15_000, 600, 400, 400),
        isLocker: true,
    );

    $destination = $point->toDestination('Anna Petrova', '+79990009988');
    $deliveryAddr = $destination->toDeliveryAddress();

    expect($destination->pointId)->toBe('msk-locker-12')
        ->and($destination->mode)->toBe(DeliveryMode::PickupPoint)
        ->and($billingAddress->lines)->toBe(['Arbat 10, apt 5'])
        ->and($deliveryAddr->lines)->toBe(['Tverskaya 7, Locker #12'])
        ->and($deliveryAddr->recipient)->toBe('Anna Petrova')
        ->and($deliveryAddr->phone)->toBe('+79990009988')
        ->and(fn () => new PickupPointDestination('p1', 'cdek', DeliveryMode::Shipping, $lockerAddress))
        ->toThrow(InvalidCommand::class, 'mode');

    $ctx = CommerceContext::for(ChannelId::generate(), Currency::of('RUB'));
    expect(fn () => new PickupPointQuery($ctx, 'RU', radiusMeters: 1000))
        ->toThrow(InvalidCommand::class, 'requires near coordinates');
});

it('builds multi-parcel BookShipment and exposes distinct tracking numbers on BookedShipment', function (): void {
    $addr = new Address('DE', city: 'Berlin', postalCode: '10115', lines: ['Torstr. 1']);
    $p1 = new Parcel(18_000, Money::of('120.00', 'EUR'), 500, 400, 300);
    $p2 = new Parcel(14_000, Money::of('80.00', 'EUR'), 450, 350, 250);

    $command = BookShipment::forParcels('fulfillment:99', 'dhl', 'dhl_standard', $addr, [$p1, $p2]);

    expect($command->parcels)->toHaveCount(2)
        ->and($command->parcel->weightGrams)->toBe(32_000)
        ->and($command->parcel->value->toDecimal())->toBe('200.00');

    $booked = new BookedShipment(
        carrier: 'dhl',
        tracking: 'DHL-001',
        labelRef: 'lbl:1',
        parcels: [
            new BookedParcel(1, 'DHL-001', $p1, 'lbl:1'),
            new BookedParcel(2, 'DHL-002', $p2, 'lbl:2'),
        ],
    );

    expect($booked->trackingNumbers())->toBe(['DHL-001', 'DHL-002']);
});
