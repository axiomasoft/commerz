<?php

declare(strict_types=1);

use Commerz\Configuration\CommerzOptions;
use Commerz\Context\CommerceContext;
use Commerz\Measures\LocalizedText;
use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Operations\Contracts\Transactions;
use Commerz\Ports\Delivery\Contracts\PickupPointProvider;
use Commerz\Ports\Delivery\Data\Address;
use Commerz\Ports\Delivery\Data\Coordinates;
use Commerz\Ports\Delivery\Data\Parcel;
use Commerz\Ports\Delivery\Data\PickupPoint;
use Commerz\Ports\Delivery\Data\PickupPointLimits;
use Commerz\Ports\Delivery\Data\PickupPointQuery;
use Commerz\Ports\Delivery\Enums\DeliveryMode;
use Commerz\Shipping\Facades\Shipping;
use Commerz\Shipping\PickupPoints\Contracts\PickupPoints;
use Commerz\Shipping\PickupPoints\Data\UpsertPickupPoint;
use Commerz\Shipping\PickupPoints\Exceptions\PickupPointNotFound;
use Commerz\Shipping\PickupPoints\Exceptions\PickupPointOversized;
use Commerz\Stores\Data\ChannelId;
use Commerz\Testkit\Fakes\FrozenClock;
use Commerz\Time\Contracts\Clock;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Packman\Plugins\Data\Plugin\PluginManifest;
use Packman\Plugins\Laravel\PluginRegistrar;

final readonly class ExternalLockerProvider implements PickupPointProvider
{
    public function key(): string
    {
        return 'inpost';
    }

    public function search(PickupPointQuery $query): array
    {
        return [
            new PickupPoint(
                id: 'inpost-ber-xl',
                provider: 'inpost',
                name: 'InPost XL Locker Alexanderplatz',
                mode: DeliveryMode::PickupPoint,
                address: new Address('DE', city: 'Berlin', postalCode: '10178', lines: ['Alexanderplatz 1']),
                coordinates: Coordinates::fromDecimal('52.521918', '13.413215'),
                limits: new PickupPointLimits(25_000, 640, 380, 410),
                isLocker: true,
            ),
        ];
    }

    public function find(string $pointId, CommerceContext $context): ?PickupPoint
    {
        return $pointId === 'inpost-ber-xl' ? $this->search(new PickupPointQuery($context, 'DE'))[0] : null;
    }
}

final class ExternalLockerPlugin implements ExtensionPlugin
{
    public function manifest(): PluginManifest
    {
        return new PluginManifest('test.inpost-lockers', '1.0.0', ['commerz.core']);
    }

    public function register(PluginRegistrar $registrar): void
    {
        $registrar->contribute(PickupPointProvider::KIND, new ExternalLockerProvider);
    }
}

beforeEach(function (): void {
    config()->set('database.connections.pudo_sqlite', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
    ]);
    config()->set('commerz.connection', 'pudo_sqlite');
    config()->set('commerz.plugins', [ExternalLockerPlugin::class]);
    app()->forgetInstance(CommerzOptions::class);
    app()->forgetInstance(PickupPoints::class);
    app()->forgetInstance(Commerz\Shipping\Shipping::class);
    DB::purge('pudo_sqlite');

    $db = DB::connection('pudo_sqlite');
    $db->statement('PRAGMA foreign_keys = ON');
    $db->statement(<<<'SQL'
        create table cmz_shipping_pickup_points (
            id text primary key,
            provider text not null,
            code text not null,
            name text not null,
            mode text not null default 'pickup_point',
            country text not null,
            region text,
            city text not null,
            city_normalized text not null,
            postal_code text,
            address_lines text not null default '[]',
            phone text,
            latitude_microdeg integer,
            longitude_microdeg integer,
            is_locker integer not null default 0,
            max_weight_grams integer,
            max_length_mm integer,
            max_width_mm integer,
            max_height_mm integer,
            opening_hours text,
            status text not null default 'active',
            meta text not null default '{}',
            created_at text not null,
            updated_at text not null,
            unique (provider, code)
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
});

it('filters out small lockers for oversized or overweight items while keeping large lockers and BOPIS stores', function (): void {
    $ctx = CommerceContext::for(ChannelId::generate(), Currency::of('EUR'), 'en');

    // 1. Small automated locker (max 400 x 300 x 200 mm, 5 kg)
    Shipping::pickupPoints()->upsert(new UpsertPickupPoint(
        provider: 'manual',
        code: 'ber-locker-small',
        name: new LocalizedText(['en' => 'Small Locker Mitte']),
        address: new Address('DE', city: 'Berlin', postalCode: '10115', lines: ['Torstr. 10']),
        mode: DeliveryMode::PickupPoint,
        coordinates: Coordinates::fromDecimal('52.528000', '13.401000'),
        limits: new PickupPointLimits(maxWeightGrams: 5_000, maxLengthMm: 400, maxWidthMm: 300, maxHeightMm: 200),
        isLocker: true,
    ));

    // 2. BOPIS flagship store (LocalPickup, accepts up to 50 kg / 1200 mm)
    Shipping::pickupPoints()->upsert(new UpsertPickupPoint(
        provider: 'manual',
        code: 'ber-flagship-bopis',
        name: new LocalizedText(['en' => 'Berlin Flagship Store']),
        address: new Address('DE', city: 'Berlin', postalCode: '10117', lines: ['Friedrichstr. 50']),
        mode: DeliveryMode::LocalPickup,
        coordinates: Coordinates::fromDecimal('52.520000', '13.388000'),
        limits: new PickupPointLimits(maxWeightGrams: 50_000, maxLengthMm: 1200, maxWidthMm: 800, maxHeightMm: 800),
        isLocker: false,
        openingHours: 'Mon-Sat 10:00-20:00',
    ));

    // Oversized item for the small locker (600 x 350 x 300 mm, 8 kg):
    // - Rejected by ber-locker-small (exceeds both 5 kg and 400 x 300 x 200 mm)
    // - Accepted by ExternalLockerProvider's inpost-ber-xl (fits 640 x 380 x 410 mm and 25 kg!)
    // - Accepted by ber-flagship-bopis (fits 1200 x 800 x 800 mm and 50 kg!)
    $oversizedParcel = new Parcel(8_000, Money::of('199.00', 'EUR'), 600, 350, 300);

    $allAvailable = Shipping::pickupPoints()->search(new PickupPointQuery(
        context: $ctx,
        country: 'DE',
        city: 'berlin',
        parcel: $oversizedParcel,
    ));

    expect(array_map(static fn (PickupPoint $p): string => $p->id, $allAvailable))
        ->toBe(['inpost-ber-xl', 'ber-flagship-bopis']);

    // Filtering by BOPIS LocalPickup only
    $bopisOnly = Shipping::pickupPoints()->search(new PickupPointQuery(
        context: $ctx,
        country: 'DE',
        mode: DeliveryMode::LocalPickup,
        parcel: $oversizedParcel,
    ));

    expect($bopisOnly)->toHaveCount(1)
        ->and($bopisOnly[0]->id)->toBe('ber-flagship-bopis')
        ->and($bopisOnly[0]->mode)->toBe(DeliveryMode::LocalPickup);

    // Coordinate radius search: within 1 km of Alexanderplatz (52.521918, 13.413215) with a small 1 kg parcel
    $smallParcel = new Parcel(1_000, Money::of('25.00', 'EUR'), 200, 150, 100);
    $nearAlex = Shipping::pickupPoints()->search(new PickupPointQuery(
        context: $ctx,
        country: 'DE',
        near: Coordinates::fromDecimal('52.521918', '13.413215'),
        radiusMeters: 1_200,
        parcel: $smallParcel,
    ));

    // inpost-ber-xl is 0 m away, ber-locker-small is ~1.06 km away, ber-flagship-bopis is ~1.72 km away (filtered out!)
    expect(array_map(static fn (PickupPoint $p): string => $p->id, $nearAlex))
        ->toBe(['inpost-ber-xl', 'ber-locker-small']);

    // validateSelection throws PickupPointOversized when buyer attempts to select the small locker for the oversized item
    $smallPoint = Shipping::pickupPoints()->find('ber-locker-small', $ctx, 'manual');
    expect(fn () => Shipping::pickupPoints()->validateSelection(
        $smallPoint->toDestination('Max Mustermann', '+491701234567'),
        $oversizedParcel,
        $ctx,
    ))->toThrow(PickupPointOversized::class);

    // Disabling hides the point from search and find
    Shipping::pickupPoints()->disable('manual', 'ber-locker-small');
    expect(fn () => Shipping::pickupPoints()->find('ber-locker-small', $ctx, 'manual'))
        ->toThrow(PickupPointNotFound::class);
});
