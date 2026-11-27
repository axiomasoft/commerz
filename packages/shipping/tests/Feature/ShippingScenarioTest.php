<?php

declare(strict_types=1);

// The package end to end, as P7 sales and a storefront use it (knowledge/shipping-design#api): zones → a method with
// tariffs → options and explain → a quote with delivery → booking through the core port, and the repeat. Booking
// refuses to run inside a transaction (SHP-04), so the test commits and empties the tables itself.
use Commerz\Measures\LocalizedText;
use Commerz\Money\Money;
use Commerz\Ports\Delivery\Contracts\DeliveryOptions;
use Commerz\Ports\Delivery\Contracts\ShipmentBooking;
use Commerz\Ports\Delivery\Data\DeliveryOption;
use Commerz\Shipping\Bookings\Enums\BookingState;
use Commerz\Shipping\Bookings\Events\ShipmentBooked;
use Commerz\Shipping\Facades\Shipping;
use Commerz\Shipping\Rates\Data\RateSpec;
use Commerz\Shipping\Zones\Data\CreateZone;
use Commerz\Shipping\Zones\Data\ZoneMember;
use Commerz\Testkit\Commerce;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

require_once __DIR__.'/Options/Helpers.php';
require_once __DIR__.'/Bookings/Helpers.php';

function sscnTruncate(): void
{
    DB::statement('truncate cmz_shipping_bookings, cmz_shipping_rates, cmz_shipping_method_channels, cmz_shipping_methods,'
        .' cmz_shipping_zone_members, cmz_shipping_zones, cmz_channels, cmz_stores cascade');
}

beforeEach(function (): void {
    mtMigrate();
    sscnTruncate();
});

afterEach(fn () => sscnTruncate());

it('takes a store from zones and tariffs to a booked shipment', function (): void {
    Event::fake([ShipmentBooked::class]);

    // Setup: Russia, Moscow inside it, a courier (weight table for Russia, flat with a free threshold for Moscow) and a pickup.
    $web = mtChannel('web');
    $ru = Shipping::zones()->create(new CreateZone('ru', 'Россия', [ZoneMember::country('RU')]));
    $msk = Shipping::zones()->create(new CreateZone('msk', 'Москва', [ZoneMember::region('RU-MOW')]));
    Shipping::methods()->create(mtCommand('courier', [
        'name' => new LocalizedText(['ru' => 'Курьер', 'en' => 'Courier']),
        'channels' => [$web],
        'position' => 10,
        'rates' => [
            mtRate($ru->id, ['rate' => new RateSpec('weight_table', ['steps' => [
                ['up_to_grams' => 1000, 'amount' => Money::of('300.00', 'RUB')],
                ['up_to_grams' => 5000, 'amount' => Money::of('500.00', 'RUB')],
            ]]), 'etaDays' => 3]),
            mtRate($msk->id, ['rate' => mtFlat('250.00'), 'freeAbove' => Money::of('5000.00', 'RUB'), 'etaDays' => 1]),
        ],
    ]));
    Shipping::methods()->create(mtCommand('pickup', ['channels' => [$web], 'position' => 20, 'rates' => [mtRate($ru->id, ['rate' => mtFlat('0.00')])]]));
    Shipping::methods()->create(mtCommand('express', ['position' => 5, 'rates' => [mtRate($ru->id, ['rate' => mtFlat('900.00')])]]));

    // Options: Moscow takes the Moscow tariff, Saint Petersburg the weight table; the port answers the same.
    expect(opList(opRequest($web, 'RU-MOW')))->toBe(['courier 250.00 msk 1', 'pickup 0.00 ru -'])
        ->and(opList(opRequest($web, 'RU-SPE')))->toBe(['courier 300.00 ru 3', 'pickup 0.00 ru -'])
        ->and(opList(opRequest($web, 'RU-MOW', value: '5000.00')))->toBe(['courier 0.00 msk 1', 'pickup 0.00 ru -'])
        ->and(array_map(
            static fn (DeliveryOption $o): string => "{$o->methodCode} {$o->price->toDecimal()}",
            app(DeliveryOptions::class)->options(opRequest($web, 'RU-MOW')),
        ))->toBe(['courier 250.00', 'pickup 0.00']);

    // Explain says why a method is missing.
    expect(opVerdicts(opRequest($web, 'RU-MOW')))->toBe(['courier' => 'available 250.00', 'express' => 'not_in_channel', 'pickup' => 'available 0.00']);

    // The testkit fakes sellables and prices for the quote; the delivery ports stay the real ones.
    $options = app(DeliveryOptions::class);
    $booking = app(ShipmentBooking::class);
    Commerce::fake();
    app()->instance(DeliveryOptions::class, $options);
    app()->instance(ShipmentBooking::class, $booking);

    // Quote: the selection prices delivery on the goods; the total carries it (SHP-01, SHP-02).
    $quote = opQuote($web, ['1000.00'], 'courier', 'RU-MOW');

    expect($quote->delivery?->price->toDecimal())->toBe('250.00')
        ->and($quote->totals->deliveryTotal->toDecimal())->toBe('250.00')
        ->and($quote->totals->total->toDecimal())->toBe('1250.00')
        ->and(Shipping::options()->list(opRequest($web, 'RU-MOW', value: '1000.00'))[0]->price->toDecimal())->toBe('250.00');

    // Booking: outside a transaction, through the core port; the manual carrier is repeatable (SHP-03, SHP-04).
    $command = bkCommand('fulfillment:1', ['tracking' => 'MAN-1'], method: 'courier', carrier: 'manual');
    $first = bkBook($command);
    $again = bkBook($command);

    expect([$first->carrier, $first->tracking, $again->tracking])->toBe(['manual', 'MAN-1', 'MAN-1'])
        ->and(Shipping::bookings()->find('fulfillment:1')->state)->toBe(BookingState::Booked)
        ->and(bkRow()->attempts)->toBe(1);

    Event::assertDispatchedTimes(ShipmentBooked::class, 1);
});
