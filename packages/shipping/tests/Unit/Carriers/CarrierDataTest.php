<?php

declare(strict_types=1);

use Commerz\Errors\CommerzException;
use Commerz\Errors\ErrorCategory;
use Commerz\Errors\InvalidCommand;
use Commerz\Money\Money;
use Commerz\Ports\Delivery\Data\Address;
use Commerz\Ports\Delivery\Data\BookShipment;
use Commerz\Ports\Delivery\Data\Parcel;
use Commerz\Shipping\Carriers\Data\CarrierBooking;
use Commerz\Shipping\Carriers\Exceptions\CarrierRejected;
use Commerz\Shipping\Carriers\Exceptions\InvalidCarrierResult;

function cdShipment(): BookShipment
{
    return new BookShipment('fulfillment:1', 'manual', 'courier', new Address('RU'), new Parcel(500, Money::of('10.00', 'RUB')));
}

it('carries the shipment and the number of the attempt, from 1', function (): void {
    $booking = new CarrierBooking(cdShipment(), 2);

    expect($booking->shipment->reference)->toBe('fulfillment:1')
        ->and($booking->attempt)->toBe(2)
        ->and(new CarrierBooking(cdShipment(), 1)->attempt)->toBe(1);
});

it('refuses an attempt below 1', function (int $attempt): void {
    expect(fn () => new CarrierBooking(cdShipment(), $attempt))
        ->toThrow(fn (InvalidCommand $e) => expect($e->code())->toBe('shipping.invalid_booking')->and($e->field)->toBe('attempt'));
})->with([0, -1]);

it('classifies the carrier errors', function (): void {
    $rejected = new CarrierRejected('cdek', 'address_not_served', 'No pickup point there.');
    $invalid = new InvalidCarrierResult('cdek', 'no tracking number');

    expect($rejected)->toBeInstanceOf(CommerzException::class)
        ->and([$rejected->code(), $rejected->category(), $rejected->context()])->toBe([
            'shipping.carrier_rejected', ErrorCategory::Integration, ['carrier' => 'cdek', 'reason' => 'address_not_served', 'detail' => 'No pickup point there.'],
        ])
        ->and($rejected->getMessage())->toContain('cdek')->toContain('address_not_served')
        ->and([$invalid->code(), $invalid->category(), $invalid->context()])->toBe([
            'shipping.invalid_carrier_result', ErrorCategory::Integration, ['carrier' => 'cdek', 'reason' => 'no tracking number'],
        ]);
});
