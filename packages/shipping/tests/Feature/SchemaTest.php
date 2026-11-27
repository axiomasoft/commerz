<?php

declare(strict_types=1);

use Commerz\Identity\Uuid7;
use Commerz\Schema\TableNames;
use Commerz\Shipping\ShippingServiceProvider;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Packman\Schema\Contracts\Compiler\SchemaOwnership;
use Packman\Schema\Data\Compiler\ConstraintNames;

uses(RefreshDatabase::class);

const SHIPPING_TABLES = [
    'shipping_zones', 'shipping_zone_members', 'shipping_methods', 'shipping_method_channels', 'shipping_rates', 'shipping_bookings',
    'shipping_pickup_points', 'shipping_booking_parcels',
];

function shpTable(string $logical): string
{
    return app(TableNames::class)->table($logical);
}

/** @return list<string> */
function shpConstraints(string $logical): array
{
    /** @var list<object{conname: string}> $rows */
    $rows = DB::select("select conname from pg_constraint where conrelid = ?::regclass and contype <> 'n' order by conname", [shpTable($logical)]);
    $names = [];

    foreach ($rows as $row) {
        $names[] = $row->conname;
    }

    return $names;
}

// RefreshDatabase wraps each test in a transaction; DB::transaction opens a savepoint that keeps it usable after an expected violation.

/** @param  array<string, mixed>  $row */
function shpInsert(string $logical, array $row): void
{
    DB::transaction(static fn () => DB::table(shpTable($logical))->insert($row));
}

/** @param  array<string, mixed>  $set */
function shpUpdate(string $logical, string $id, array $set): void
{
    DB::transaction(static fn () => DB::table(shpTable($logical))->where('id', $id)->update($set));
}

function shpDelete(string $logical, string $id): void
{
    DB::transaction(static fn () => DB::table(shpTable($logical))->where('id', $id)->delete());
}

/**
 * The SQLSTATE a statement fails with, or null when it succeeds.
 *
 * @param  Closure(): mixed  $statement
 */
function shpSqlState(Closure $statement): ?string
{
    try {
        $statement();
    } catch (QueryException $e) {
        $state = $e->errorInfo[0] ?? null;

        return is_string($state) ? $state : null;
    }

    return null;
}

/** @param  array<string, mixed>  $extra */
function shpZone(string $code = 'russia', array $extra = []): string
{
    $id = Uuid7::generate();

    shpInsert('shipping_zones', ['id' => $id, 'code' => $code, 'name' => ucfirst($code), ...$extra]);

    return $id;
}

/** @param  array<string, mixed>  $extra */
function shpMember(string $zone, array $extra = []): void
{
    shpInsert('shipping_zone_members', ['shipping_zone_id' => $zone, 'country' => 'RU', ...$extra]);
}

/** @param  array<string, mixed>  $extra */
function shpMethod(string $code = 'courier', array $extra = []): string
{
    $id = Uuid7::generate();

    shpInsert('shipping_methods', ['id' => $id, 'code' => $code, 'name' => '{"en":"Courier"}', 'carrier' => 'manual', ...$extra]);

    return $id;
}

/** @param  array<string, mixed>  $extra */
function shpRate(string $method, string $zone, array $extra = []): void
{
    shpInsert('shipping_rates', [
        'id' => Uuid7::generate(), 'shipping_method_id' => $method, 'shipping_zone_id' => $zone, 'currency' => 'RUB',
        'rate_key' => 'flat', 'rate_version' => 1, 'params' => '{}', 'tax_included' => false, ...$extra,
    ]);
}

/** @param  array<string, mixed>  $extra */
function shpBooking(string $reference = 'fulfillment:1', array $extra = []): string
{
    $id = Uuid7::generate();

    shpInsert('shipping_bookings', [
        'id' => $id, 'reference' => $reference, 'carrier' => 'manual', 'method_code' => 'courier', 'fingerprint' => str_repeat('a', 64),
        'state' => 'pending', 'attempts' => 1, 'claimed_at' => '2026-10-13 10:00:00+00', ...$extra,
    ]);

    return $id;
}

it('declares the shipping noun under commerz.shipping', function (): void {
    $ownership = app(SchemaOwnership::class);

    expect($ownership->owners()['commerz.shipping'])->toBe(ShippingServiceProvider::NOUNS)
        ->and($ownership->ownerOf('shipping'))->toBe('commerz.shipping');
});

it('creates every table with canonical constraint names', function (): void {
    foreach (SHIPPING_TABLES as $logical) {
        $physical = shpTable($logical);

        expect(DB::getSchemaBuilder()->hasTable($physical))->toBeTrue("table {$physical}");

        foreach (shpConstraints($logical) as $name) {
            expect($name)->toMatch('/(_(pk|uq|fk|ck|ex)|_[0-9a-f]{8})\z/');
        }

        /** @var list<object{indexname: string}> $indexes */
        $indexes = DB::select('select indexname from pg_indexes where tablename = ?', [$physical]);

        foreach ($indexes as $index) {
            expect($index->indexname)->toMatch('/(_(pk|uq|ix|ex)|_[0-9a-f]{8})\z/');
        }

        expect(shpConstraints($logical))->toContain(ConstraintNames::primary($physical));
    }
});

it('keeps money in bigint, ids as uuid, the tariff currency not null and parameters as jsonb', function (): void {
    /** @return object{data_type: string, is_nullable: string} */
    $column = static function (string $logical, string $name): object {
        /** @var object{data_type: string, is_nullable: string}|null $row */
        $row = DB::selectOne(
            'select data_type, is_nullable from information_schema.columns where table_name = ? and column_name = ?',
            [shpTable($logical), $name],
        );

        if (! is_object($row)) {
            throw new RuntimeException("missing column {$logical}.{$name}");
        }

        return $row;
    };

    expect($column('shipping_rates', 'free_above_minor')->data_type)->toBe('bigint')
        ->and($column('shipping_rates', 'free_above_minor')->is_nullable)->toBe('YES')
        ->and($column('shipping_rates', 'currency')->data_type)->toBe('character')
        ->and($column('shipping_rates', 'currency')->is_nullable)->toBe('NO')
        ->and($column('shipping_rates', 'params')->data_type)->toBe('jsonb')
        ->and($column('shipping_methods', 'name')->data_type)->toBe('jsonb')
        ->and($column('shipping_method_channels', 'channel_id')->data_type)->toBe('uuid')
        ->and($column('shipping_zone_members', 'id')->data_type)->toBe('bigint')
        ->and($column('shipping_bookings', 'claimed_at')->data_type)->toBe('timestamp with time zone')
        ->and($column('shipping_bookings', 'result')->data_type)->toBe('jsonb');
});

it('has no foreign key to the core or other packages', function (): void {
    $prefix = substr(shpTable('shipping_zones'), 0, -strlen('shipping_zones'));

    foreach (SHIPPING_TABLES as $logical) {
        /** @var list<object{target: string}> $targets */
        $targets = DB::select(
            "select confrelid::regclass::text as target from pg_constraint where conrelid = ?::regclass and contype = 'f'",
            [shpTable($logical)],
        );

        foreach ($targets as $row) {
            expect($row->target)->toMatch('/\A'.preg_quote($prefix, '/').'shipping_/', "{$logical} references {$row->target}");
        }
    }
});

it('keeps zone codes unique and canonical and checks the status', function (): void {
    $id = shpZone();

    expect(fn () => shpZone('russia'))->toThrow(UniqueConstraintViolationException::class, 'cmz_shipping_zones_code_uq')
        ->and(fn () => shpZone('Russia'))->toThrow(QueryException::class, 'cmz_shipping_zones_code_valid_ck')
        ->and(fn () => shpZone('-russia'))->toThrow(QueryException::class, 'cmz_shipping_zones_code_valid_ck')
        ->and(fn () => shpZone('a b'))->toThrow(QueryException::class, 'cmz_shipping_zones_code_valid_ck')
        ->and(fn () => shpUpdate('shipping_zones', $id, ['status' => 'deleted']))->toThrow(QueryException::class, 'cmz_shipping_zones_status_valid_ck')
        ->and(shpSqlState(static fn () => shpUpdate('shipping_zones', $id, ['status' => 'archived'])))->toBeNull()
        ->and(DB::table(shpTable('shipping_zones'))->where('id', $id)->value('status'))->toBe('archived')
        ->and(shpSqlState(static fn () => shpZone(str_repeat('a', 64), ['code' => str_repeat('a', 64)])))->toBeNull();

    expect(DB::table(shpTable('shipping_zones'))->where('code', 'russia')->value('status'))->toBe('archived');
});

it('checks the country, region and postal prefix of a zone member', function (): void {
    $zone = shpZone();
    $member = static function (array $extra) use ($zone): Closure {
        $clean = [];

        foreach ($extra as $key => $value) {
            if (! is_string($key)) {
                throw new InvalidArgumentException('member columns must be a map');
            }

            $clean[$key] = $value;
        }

        return static fn () => shpMember($zone, $clean);
    };

    expect($member(['country' => 'ru']))->toThrow(QueryException::class, 'cmz_shipping_zone_members_country_valid_ck')
        ->and($member(['country' => 'RUS']))->toThrow(QueryException::class)
        ->and($member(['region' => 'RU-mow']))->toThrow(QueryException::class, 'cmz_shipping_zone_members_region_valid_ck')
        ->and($member(['region' => 'DE-BE']))->toThrow(QueryException::class, 'cmz_shipping_zone_members_region_valid_ck')
        ->and($member(['postal_prefix' => '10 1']))->toThrow(QueryException::class, 'cmz_shipping_zone_members_postal_prefix_valid_ck')
        ->and($member(['postal_prefix' => '']))->toThrow(QueryException::class, 'cmz_shipping_zone_members_postal_prefix_valid_ck')
        ->and($member(['postal_prefix' => 'abc']))->toThrow(QueryException::class, 'cmz_shipping_zone_members_postal_prefix_valid_ck')
        ->and($member(['region' => 'RU-MOW', 'postal_prefix' => '101']))->toThrow(QueryException::class, 'cmz_shipping_zone_members_region_xor_postal_ck')
        ->and(shpSqlState($member([])))->toBeNull()
        ->and(shpSqlState($member(['region' => 'RU-MOW'])))->toBeNull()
        ->and(shpSqlState($member(['postal_prefix' => '101'])))->toBeNull()
        ->and(shpSqlState($member(['country' => 'GB', 'postal_prefix' => 'SW1A'])))->toBeNull();
});

it('does not repeat a zone member, even when its region or prefix is NULL', function (): void {
    $zone = shpZone();
    $other = shpZone('europe');
    shpMember($zone);
    shpMember($zone, ['region' => 'RU-MOW']);
    shpMember($zone, ['postal_prefix' => '101']);

    expect(fn () => shpMember($zone))->toThrow(UniqueConstraintViolationException::class, ConstraintNames::unique(shpTable('shipping_zone_members'), ['shipping_zone_id', 'country', 'region', 'postal_prefix']))
        ->and(fn () => shpMember($zone, ['region' => 'RU-MOW']))->toThrow(UniqueConstraintViolationException::class)
        ->and(fn () => shpMember($zone, ['postal_prefix' => '101']))->toThrow(UniqueConstraintViolationException::class)
        ->and(shpSqlState(static fn () => shpMember($zone, ['postal_prefix' => '102'])))->toBeNull()
        ->and(shpSqlState(static fn () => shpMember($other)))->toBeNull(); // another zone may overlap
});

it('removes the members with their zone and refuses a member of no zone', function (): void {
    $zone = shpZone();
    shpMember($zone);

    expect(fn () => shpMember(Uuid7::generate()))->toThrow(QueryException::class, 'fk');

    shpDelete('shipping_zones', $zone);

    expect(DB::table(shpTable('shipping_zone_members'))->count())->toBe(0);
});

it('keeps method codes unique and checks code, carrier, status and name', function (): void {
    $id = shpMethod();

    expect(fn () => shpMethod('courier'))->toThrow(UniqueConstraintViolationException::class, 'cmz_shipping_methods_code_uq')
        ->and(fn () => shpMethod('Courier2'))->toThrow(QueryException::class, 'cmz_shipping_methods_code_valid_ck')
        ->and(fn () => shpMethod('pickup', ['carrier' => 'Manual']))->toThrow(QueryException::class, 'cmz_shipping_methods_carrier_valid_ck')
        ->and(fn () => shpMethod('pickup', ['carrier' => '']))->toThrow(QueryException::class, 'cmz_shipping_methods_carrier_valid_ck')
        ->and(fn () => shpMethod('pickup', ['name' => '["Courier"]']))->toThrow(QueryException::class, 'cmz_shipping_methods_name_object_ck')
        ->and(fn () => shpUpdate('shipping_methods', $id, ['status' => 'deleted']))->toThrow(QueryException::class, 'cmz_shipping_methods_status_valid_ck');

    foreach (['active', 'disabled', 'archived'] as $status) {
        expect(shpSqlState(static fn () => shpUpdate('shipping_methods', $id, ['status' => $status])))->toBeNull();
    }

    $other = shpMethod('pickup');
    $record = DB::table(shpTable('shipping_methods'))->where('id', $other)->first();

    if (! is_object($record)) {
        throw new RuntimeException('method row missing');
    }

    $values = get_object_vars($record);
    $status = $values['status'] ?? null;
    $position = $values['position'] ?? null;

    if (! is_string($status) || ! is_int($position)) {
        throw new RuntimeException('method row is incomplete');
    }

    expect($status)->toBe('active')->and($position)->toBe(0);
});

it('assigns a method to a channel once and removes the assignment with the method', function (): void {
    $method = shpMethod();
    $channel = Uuid7::generate(); // not a real channel: assignments carry no foreign key to the core table
    shpInsert('shipping_method_channels', ['shipping_method_id' => $method, 'channel_id' => $channel]);

    expect(fn () => shpInsert('shipping_method_channels', ['shipping_method_id' => $method, 'channel_id' => $channel]))->toThrow(UniqueConstraintViolationException::class)
        ->and(fn () => shpInsert('shipping_method_channels', ['shipping_method_id' => Uuid7::generate(), 'channel_id' => $channel]))->toThrow(QueryException::class, 'cmz_shipping_method_channels_shipping_method_id_fk');

    shpDelete('shipping_methods', $method);

    expect(DB::table(shpTable('shipping_method_channels'))->count())->toBe(0);
});

it('keeps one rate per method, zone and currency (SHP-02)', function (): void {
    $method = shpMethod();
    $zone = shpZone();
    shpRate($method, $zone);

    expect(fn () => shpRate($method, $zone))->toThrow(UniqueConstraintViolationException::class, ConstraintNames::unique(shpTable('shipping_rates'), ['shipping_method_id', 'shipping_zone_id', 'currency']))
        ->and(shpSqlState(static fn () => shpRate($method, $zone, ['currency' => 'USD'])))->toBeNull()
        ->and(shpSqlState(static fn () => shpRate($method, shpZone('europe'))))->toBeNull()
        ->and(shpSqlState(static fn () => shpRate(shpMethod('pickup'), $zone)))->toBeNull();
});

it('checks the currency, version, threshold, eta and parameters of a rate', function (): void {
    $method = shpMethod();
    $zone = shpZone();
    $rate = static function (array $extra) use ($method, $zone): Closure {
        $clean = [];

        foreach ($extra as $key => $value) {
            if (! is_string($key)) {
                throw new InvalidArgumentException('rate columns must be a map');
            }

            $clean[$key] = $value;
        }

        return static fn () => shpRate($method, $zone, $clean);
    };

    expect($rate(['currency' => 'rub']))->toThrow(QueryException::class, 'cmz_shipping_rates_currency_valid_ck')
        ->and($rate(['currency' => null]))->toThrow(QueryException::class)
        ->and($rate(['rate_version' => 0]))->toThrow(QueryException::class, 'cmz_shipping_rates_rate_version_positive_ck')
        ->and($rate(['free_above_minor' => -1]))->toThrow(QueryException::class, 'cmz_shipping_rates_free_above_non_negative_ck')
        ->and($rate(['eta_days' => -1]))->toThrow(QueryException::class, 'cmz_shipping_rates_eta_days_valid_ck')
        ->and($rate(['eta_days' => 366]))->toThrow(QueryException::class, 'cmz_shipping_rates_eta_days_valid_ck')
        ->and($rate(['params' => '[]']))->toThrow(QueryException::class, 'cmz_shipping_rates_params_object_ck')
        ->and($rate(['tax_included' => null]))->toThrow(QueryException::class)
        ->and(shpSqlState($rate(['free_above_minor' => 0, 'eta_days' => 0])))->toBeNull()
        ->and(shpSqlState($rate(['currency' => 'EUR', 'free_above_minor' => 500000, 'eta_days' => 365])))->toBeNull();
});

it('removes the rates with their method but refuses to remove a zone that has rates', function (): void {
    $method = shpMethod();
    $zone = shpZone();
    shpRate($method, $zone);

    expect(fn () => shpDelete('shipping_zones', $zone))->toThrow(QueryException::class, 'cmz_shipping_rates_shipping_zone_id_fk');

    shpDelete('shipping_methods', $method);

    expect(DB::table(shpTable('shipping_rates'))->count())->toBe(0);
});

it('keeps one booking per reference and checks the state and attempts (SHP-03)', function (): void {
    shpBooking('fulfillment:1');
    $update = static function (string $id, array $set): Closure {
        $clean = [];

        foreach ($set as $key => $value) {
            if (! is_string($key)) {
                throw new InvalidArgumentException('booking columns must be a map');
            }

            $clean[$key] = $value;
        }

        return static fn () => shpUpdate('shipping_bookings', $id, $clean);
    };
    $id = shpBooking('fulfillment:2');

    expect(fn () => shpBooking('fulfillment:1'))->toThrow(UniqueConstraintViolationException::class, 'cmz_shipping_bookings_reference_uq')
        ->and(fn () => shpBooking('fulfillment:3', ['state' => 'done']))->toThrow(QueryException::class, 'cmz_shipping_bookings_state_valid_ck')
        ->and(fn () => shpBooking('fulfillment:3', ['attempts' => 0]))->toThrow(QueryException::class, 'cmz_shipping_bookings_attempts_positive_ck')
        ->and(fn () => shpBooking('fulfillment:3', ['fingerprint' => str_repeat('a', 65)]))->toThrow(QueryException::class)
        ->and(fn () => shpBooking('fulfillment:3', ['claimed_at' => null]))->toThrow(QueryException::class)
        ->and(shpSqlState($update($id, ['state' => 'unknown', 'attempts' => 2])))->toBeNull()
        ->and(shpSqlState($update($id, ['state' => 'rejected', 'error_code' => 'shipping.carrier_rejected', 'error_message' => 'No.'])))->toBeNull();
});

it('lets a booking be booked only with a tracking number and a booking time', function (): void {
    $id = shpBooking();
    $update = static function (array $set) use ($id): Closure {
        $clean = [];

        foreach ($set as $key => $value) {
            if (! is_string($key)) {
                throw new InvalidArgumentException('booking columns must be a map');
            }

            $clean[$key] = $value;
        }

        return static fn () => shpUpdate('shipping_bookings', $id, $clean);
    };

    expect($update(['state' => 'booked']))->toThrow(QueryException::class, 'cmz_shipping_bookings_booked_has_tracking_ck')
        ->and($update(['state' => 'booked', 'tracking' => 'T-1']))->toThrow(QueryException::class, 'cmz_shipping_bookings_booked_has_tracking_ck')
        ->and($update(['state' => 'booked', 'booked_at' => '2026-10-13 10:01:00+00']))->toThrow(QueryException::class, 'cmz_shipping_bookings_booked_has_tracking_ck')
        ->and(shpSqlState($update(['state' => 'booked', 'tracking' => 'T-1', 'booked_at' => '2026-10-13 10:01:00+00', 'result' => '{"parcel":"1"}'])))->toBeNull();
});
