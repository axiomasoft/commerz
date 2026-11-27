<?php

declare(strict_types=1);

use Commerz\Money\Money;
use Commerz\Ports\Delivery\Data\Address;
use Commerz\Ports\Delivery\Data\BookShipment;
use Commerz\Ports\Delivery\Data\Parcel;
use Commerz\Shipping\Carriers\Data\CarrierBooking;
use Commerz\Shipping\Carriers\Internal\ManualCarrier;

/** @param  array<string, scalar>  $meta */
function mcBooking(array $meta = [], int $attempt = 1): CarrierBooking
{
    return new CarrierBooking(
        new BookShipment('fulfillment:7', 'manual', 'courier', new Address('RU'), new Parcel(500, Money::of('10.00', 'RUB')), $meta),
        $attempt,
    );
}

it('has the key manual', function (): void {
    expect((new ManualCarrier)->key())->toBe('manual');
});

it('books with the tracking number it was given', function (): void {
    $booked = (new ManualCarrier)->book(mcBooking(['tracking' => 'TRK-42']));

    expect($booked->carrier)->toBe('manual')
        ->and($booked->tracking)->toBe('TRK-42')
        ->and($booked->labelRef)->toBeNull()
        ->and($booked->meta)->toBe([]);
});

it('falls back to the reference when there is no usable tracking number', function (array $meta): void {
    $clean = [];

    foreach ($meta as $key => $value) {
        if (! is_string($key) || ! is_scalar($value)) {
            throw new InvalidArgumentException('booking meta must be scalar.');
        }

        $clean[$key] = $value;
    }

    expect((new ManualCarrier)->book(mcBooking($clean))->tracking)->toBe('fulfillment:7');
})->with([
    'none' => [[]],
    'empty' => [['tracking' => '']],
    'not a string' => [['tracking' => 42]],
    'over 191 bytes' => [['tracking' => str_repeat('T', 192)]],
]);

it('accepts a tracking number of 191 bytes', function (): void {
    expect((new ManualCarrier)->book(mcBooking(['tracking' => str_repeat('T', 191)]))->tracking)->toHaveLength(191);
});

it('books the same way again, and finds nothing: a repeat is always safe', function (): void {
    $carrier = new ManualCarrier;

    expect($carrier->book(mcBooking([], 2)))->toEqual($carrier->book(mcBooking()))
        ->and($carrier->find(mcBooking()))->toBeNull();
});
