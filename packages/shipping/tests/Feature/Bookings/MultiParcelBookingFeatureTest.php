<?php

declare(strict_types=1);

use Commerz\Configuration\CommerzOptions;
use Commerz\Measures\LocalizedText;
use Commerz\Money\Money;
use Commerz\Operations\Contracts\Transactions;
use Commerz\Ports\Delivery\Contracts\ShipmentBooking;
use Commerz\Ports\Delivery\Data\Address;
use Commerz\Ports\Delivery\Data\BookedParcel;
use Commerz\Ports\Delivery\Data\BookedShipment;
use Commerz\Ports\Delivery\Data\BookShipment;
use Commerz\Ports\Delivery\Data\Parcel;
use Commerz\Shipping\Bookings\Contracts\Bookings;
use Commerz\Shipping\Bookings\Events\ShipmentBooked;
use Commerz\Shipping\Carriers\Contracts\Carrier;
use Commerz\Shipping\Carriers\Data\CarrierBooking;
use Commerz\Shipping\Carriers\Exceptions\InvalidCarrierResult;
use Commerz\Shipping\Facades\Shipping;
use Commerz\Shipping\Methods\Contracts\Methods;
use Commerz\Shipping\Methods\Data\CreateMethod;
use Commerz\Shipping\Packing\Data\PackableItem;
use Commerz\Shipping\Packing\Data\PackingConstraints;
use Commerz\Shipping\Packing\Data\PackingRequest;
use Commerz\Testkit\Fakes\FrozenClock;
use Commerz\Time\Contracts\Clock;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Packman\Plugins\Data\Plugin\PluginManifest;
use Packman\Plugins\Laravel\PluginRegistrar;

final readonly class DuplicateTrackingCarrier implements Carrier
{
    public function key(): string
    {
        return 'bad_dup_carrier';
    }

    public function book(CarrierBooking $booking): BookedShipment
    {
        $parcels = [];
        foreach ($booking->shipment->parcels as $idx => $parcel) {
            $parcels[] = new BookedParcel($idx + 1, 'SAME-TRK-999', $parcel);
        }

        return new BookedShipment($this->key(), 'SAME-TRK-999', parcels: $parcels);
    }

    public function find(CarrierBooking $booking): ?BookedShipment
    {
        return null;
    }
}

final class MultiParcelTestPlugin implements ExtensionPlugin
{
    public function manifest(): PluginManifest
    {
        return new PluginManifest('test.multiparcel-carrier', '1.0.0', ['commerz.shipping']);
    }

    public function register(PluginRegistrar $registrar): void
    {
        $registrar->contribute(Carrier::KIND, new DuplicateTrackingCarrier);
    }
}

beforeEach(function (): void {
    config()->set('database.connections.mp_sqlite', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
    ]);
    config()->set('commerz.connection', 'mp_sqlite');
    config()->set('commerz.plugins', [MultiParcelTestPlugin::class]);
    app()->forgetInstance(CommerzOptions::class);
    app()->forgetInstance(Methods::class);
    app()->forgetInstance(Bookings::class);
    app()->forgetInstance(ShipmentBooking::class);
    app()->forgetInstance(Commerz\Shipping\Shipping::class);
    DB::purge('mp_sqlite');

    $db = DB::connection('mp_sqlite');
    $db->statement('PRAGMA foreign_keys = ON');

    $db->statement(<<<'SQL'
        create table cmz_shipping_methods (
            id text primary key,
            code text not null unique,
            name text not null,
            carrier text not null,
            delivery_mode text not null default 'shipping',
            status text not null default 'active',
            position integer not null default 0,
            created_at text not null,
            updated_at text not null
        )
    SQL);

    $db->statement(<<<'SQL'
        create table cmz_shipping_method_channels (
            shipping_method_id text not null references cmz_shipping_methods(id) on delete cascade,
            channel_id text not null,
            created_at text not null,
            primary key (shipping_method_id, channel_id)
        )
    SQL);

    $db->statement(<<<'SQL'
        create table cmz_shipping_zones (
            id text primary key,
            code text not null unique,
            name text not null,
            status text not null default 'active',
            created_at text not null,
            updated_at text not null
        )
    SQL);

    $db->statement(<<<'SQL'
        create table cmz_shipping_rates (
            id text primary key,
            shipping_method_id text not null references cmz_shipping_methods(id) on delete cascade,
            shipping_zone_id text not null references cmz_shipping_zones(id),
            currency text not null,
            rate_key text not null,
            rate_version integer not null,
            params text not null,
            free_above_minor integer,
            tax_included integer not null,
            eta_days integer,
            created_at text not null,
            updated_at text not null
        )
    SQL);

    $db->statement(<<<'SQL'
        create table cmz_shipping_bookings (
            id text primary key,
            reference text not null unique,
            carrier text not null,
            method_code text not null,
            fingerprint text not null,
            state text not null,
            attempts integer not null,
            claimed_at text not null,
            tracking text,
            label_ref text,
            result text,
            error_code text,
            error_message text,
            booked_at text,
            created_at text not null,
            updated_at text not null
        )
    SQL);

    $db->statement(<<<'SQL'
        create table cmz_shipping_booking_parcels (
            id text primary key,
            shipping_booking_id text not null references cmz_shipping_bookings(id) on delete cascade,
            sequence integer not null,
            tracking text not null,
            label_ref text,
            weight_grams integer not null,
            currency text not null,
            value_minor integer not null,
            length_mm integer,
            width_mm integer,
            height_mm integer,
            meta text not null default '{}',
            created_at text not null,
            updated_at text not null,
            unique (shipping_booking_id, sequence)
        )
    SQL);

    app()->singleton(Transactions::class, static fn (): Transactions => new class($db) implements Transactions
    {
        public function __construct(private readonly ConnectionInterface $db) {}

        public function run(callable $work, int $lockTimeoutMs = 3000): mixed
        {
            return $this->db->transaction(static fn (): mixed => $work());
        }

        public function inTransaction(): bool
        {
            return $this->db->transactionLevel() > 0;
        }
    });

    app()->singleton(Clock::class, static fn (): Clock => new FrozenClock(new DateTimeImmutable('2026-11-26T12:00:00Z')));

    Shipping::methods()->create(new CreateMethod(
        code: 'freight_manual',
        name: new LocalizedText(['en' => 'Freight Manual']),
        carrier: 'manual',
    ));

    Shipping::methods()->create(new CreateMethod(
        code: 'dup_method',
        name: new LocalizedText(['en' => 'Dup Carrier']),
        carrier: 'bad_dup_carrier',
    ));
});

it('packs a heavy order into 3 parcels and books them with distinct tracking numbers stored in shipping_booking_parcels', function (): void {
    Event::fake([ShipmentBooked::class]);

    // Heavy order: 5 x 12 kg = 60 kg total under 25 kg maxWeightGrams -> 3 parcels (24 kg, 24 kg, 12 kg)
    $packed = Shipping::packer()->pack(new PackingRequest(
        items: [
            PackableItem::ofUnitPrice(
                itemId: 'order-line-1',
                quantity: 5,
                unitWeightGrams: 12_000,
                unitPrice: Money::of('100.00', 'EUR'),
                lengthMm: 400,
                widthMm: 300,
                heightMm: 200,
            ),
        ],
        constraints: new PackingConstraints(maxWeightGrams: 25_000),
    ));

    expect($packed->parcels)->toHaveCount(3);

    $destination = new Address('DE', city: 'Munich', postalCode: '80331', lines: ['Marienplatz 1'], recipient: 'Hans', phone: '+4989123456');
    $command = BookShipment::forParcels(
        reference: 'fulfillment:heavy-1',
        carrier: 'manual',
        methodCode: 'freight_manual',
        destination: $destination,
        parcels: $packed->toParcels(),
        meta: ['tracking' => 'MUC-900'],
    );

    /** @var ShipmentBooking $bookingPort */
    $bookingPort = app(ShipmentBooking::class);
    $booked = $bookingPort->book($command);

    expect($booked->parcels)->toHaveCount(3)
        ->and($booked->trackingNumbers())->toBe(['MUC-900-P1', 'MUC-900-P2', 'MUC-900-P3'])
        ->and(count(array_unique($booked->trackingNumbers())))->toBe(3);

    // Idempotent replay returns identical multi-parcel BookedShipment
    $replayed = $bookingPort->book($command);
    expect($replayed)->toEqual($booked);

    // Bookings read contract returns hydrated parcels & distinct tracking numbers
    $view = Shipping::bookings()->find('fulfillment:heavy-1');
    expect($view->parcels)->toHaveCount(3)
        ->and($view->trackingNumbers())->toBe(['MUC-900-P1', 'MUC-900-P2', 'MUC-900-P3'])
        ->and($view->parcels[0]->parcel->weightGrams)->toBe(24_000)
        ->and($view->parcels[1]->parcel->weightGrams)->toBe(24_000)
        ->and($view->parcels[2]->parcel->weightGrams)->toBe(12_000)
        ->and($view->parcels[0]->parcel->value->toDecimal())->toBe('200.00')
        ->and($view->parcels[1]->parcel->value->toDecimal())->toBe('200.00')
        ->and($view->parcels[2]->parcel->value->toDecimal())->toBe('100.00');

    Event::assertDispatchedTimes(ShipmentBooked::class, 1);
    Event::assertDispatched(ShipmentBooked::class, static fn (ShipmentBooked $e): bool => $e->trackingNumbers === ['MUC-900-P1', 'MUC-900-P2', 'MUC-900-P3']);

    // GDPR erasure clears parcel metadata while keeping accounting/tracking structure intact
    expect(Shipping::bookings()->erasePersonalData(['fulfillment:heavy-1']))->toBe(1);
});

it('rejects a multi-parcel carrier result that reuses duplicate tracking numbers across parcels', function (): void {
    $destination = new Address('DE', city: 'Munich', postalCode: '80331', lines: ['Marienplatz 1']);
    $command = BookShipment::forParcels(
        reference: 'fulfillment:dup-track',
        carrier: 'bad_dup_carrier',
        methodCode: 'dup_method',
        destination: $destination,
        parcels: [
            new Parcel(10_000, Money::of('50.00', 'EUR')),
            new Parcel(10_000, Money::of('50.00', 'EUR')),
        ],
    );

    expect(fn () => app(ShipmentBooking::class)->book($command))
        ->toThrow(InvalidCarrierResult::class, 'distinct tracking numbers');
});
