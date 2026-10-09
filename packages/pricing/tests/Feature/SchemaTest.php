<?php

declare(strict_types=1);

use Commerz\Identity\Uuid7;
use Commerz\Pricing\PricingServiceProvider;
use Commerz\Schema\ConstraintNames;
use Commerz\Schema\Contracts\SchemaOwnership;
use Commerz\Schema\TableNames;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

const PRICING_TABLES = [
    'price_lists', 'price_list_channels', 'prices', 'tax_classes', 'tax_zones', 'tax_zone_members', 'tax_rates',
];

function pricingTable(string $logical): string
{
    return app(TableNames::class)->table($logical);
}

/** @return list<string> */
function pricingConstraints(string $logical): array
{
    return array_map(
        static fn (object $row): string => $row->conname,
        DB::select('select conname from pg_constraint where conrelid = ?::regclass order by conname', [pricingTable($logical)]),
    );
}

// RefreshDatabase wraps each test in a transaction; a savepoint keeps it usable after an expected violation.

/** @param  array<string, mixed>  $row */
function pricingInsert(string $logical, array $row): void
{
    DB::transaction(static fn () => DB::table(pricingTable($logical))->insert($row));
}

/** @param  array<string, mixed>  $set */
function pricingUpdate(string $logical, string $id, array $set): void
{
    DB::transaction(static fn () => DB::table(pricingTable($logical))->where('id', $id)->update($set));
}

/** The SQLSTATE a statement fails with, or null when it succeeds. */
function pricingSqlState(Closure $statement): ?string
{
    try {
        $statement();
    } catch (QueryException $e) {
        return $e->errorInfo[0] ?? null;
    }

    return null;
}

function pricingList(string $code = 'retail', array $extra = []): string
{
    $id = Uuid7::generate();

    pricingInsert('price_lists', ['id' => $id, 'code' => $code, 'name' => ucfirst($code), 'currency' => 'RUB', ...$extra]);

    return $id;
}

/** @return array<string, mixed> */
function pricingPrice(string $list, array $extra = []): array
{
    return [
        'id' => Uuid7::generate(), 'price_list_id' => $list, 'sellable_ref' => 'variant:1', 'amount_minor' => 1000,
        'currency' => 'RUB', ...$extra,
    ];
}

function pricingClass(string $code = 'standard'): string
{
    $id = Uuid7::generate();

    pricingInsert('tax_classes', ['id' => $id, 'code' => $code, 'name' => ucfirst($code)]);

    return $id;
}

function pricingZone(string $code = 'ru'): string
{
    $id = Uuid7::generate();

    pricingInsert('tax_zones', ['id' => $id, 'code' => $code, 'name' => strtoupper($code)]);

    return $id;
}

/** @return array<string, mixed> */
function pricingRate(string $class, string $zone, array $extra = []): array
{
    return ['id' => Uuid7::generate(), 'tax_class_id' => $class, 'tax_zone_id' => $zone, 'rate_bp' => 2200, ...$extra];
}

it('declares the price and tax nouns under commerz.pricing', function (): void {
    $ownership = app(SchemaOwnership::class);

    expect($ownership->owners()['commerz.pricing'])->toBe(PricingServiceProvider::NOUNS)
        ->and($ownership->ownerOf('price'))->toBe('commerz.pricing')
        ->and($ownership->ownerOf('tax'))->toBe('commerz.pricing');
});

it('creates every table with canonical constraint names', function (): void {
    foreach (PRICING_TABLES as $logical) {
        $physical = pricingTable($logical);

        expect(DB::getSchemaBuilder()->hasTable($physical))->toBeTrue("table {$physical}");

        foreach (pricingConstraints($logical) as $name) {
            expect($name)->toMatch('/(_(pk|uq|fk|ck|ex)|_[0-9a-f]{8})\z/'); // a name over 63 bytes ends in the hash of ConstraintNames::shorten
        }

        foreach (DB::select('select indexname from pg_indexes where tablename = ?', [$physical]) as $index) {
            expect($index->indexname)->toMatch('/(_(pk|uq|ix|ex)|_[0-9a-f]{8})\z/');
        }

        expect(pricingConstraints($logical))->toContain(ConstraintNames::primary($physical));
    }
});

it('keeps money and quantities in bigint, rates in int and ids as uuid', function (): void {
    $type = static fn (string $logical, string $column): string => DB::selectOne(
        'select data_type from information_schema.columns where table_name = ? and column_name = ?',
        [pricingTable($logical), $column],
    )->data_type;

    expect($type('prices', 'amount_minor'))->toBe('bigint')
        ->and($type('prices', 'compare_at_minor'))->toBe('bigint')
        ->and($type('prices', 'min_quantity'))->toBe('bigint')
        ->and($type('price_lists', 'priority'))->toBe('integer')
        ->and($type('tax_rates', 'rate_bp'))->toBe('integer')
        ->and($type('tax_zone_members', 'id'))->toBe('bigint')
        ->and($type('price_list_channels', 'channel_id'))->toBe('uuid')
        ->and($type('prices', 'valid_from'))->toBe('timestamp with time zone');
});

it('has no foreign key to the core or other packages', function (): void {
    $prefix = substr(pricingTable('price_lists'), 0, -strlen('price_lists'));

    foreach (PRICING_TABLES as $logical) {
        $targets = DB::select(
            "select confrelid::regclass::text as target from pg_constraint where conrelid = ?::regclass and contype = 'f'",
            [pricingTable($logical)],
        );

        foreach ($targets as $row) {
            expect($row->target)->toMatch('/\A'.preg_quote($prefix, '/').'(price|tax)_/', "{$logical} references {$row->target}");
        }
    }
});

it('keeps price list codes unique and checks status, defaults and the channel assignment', function (): void {
    $list = pricingList();

    expect(fn () => pricingList('retail'))
        ->toThrow(UniqueConstraintViolationException::class, 'cmz_price_lists_code_uq')
        ->and(fn () => pricingUpdate('price_lists', $list, ['status' => 'deleted']))
        ->toThrow(QueryException::class, 'cmz_price_lists_status_valid_ck');

    $row = DB::table(pricingTable('price_lists'))->where('id', $list)->first();
    expect($row->priority)->toBe(0)->and($row->status)->toBe('active')->and($row->tax_included)->toBeNull();

    $channel = Uuid7::generate(); // not a real channel: assignments carry no foreign key to the core table
    pricingInsert('price_list_channels', ['price_list_id' => $list, 'channel_id' => $channel]);

    expect(fn () => pricingInsert('price_list_channels', ['price_list_id' => $list, 'channel_id' => $channel]))
        ->toThrow(UniqueConstraintViolationException::class)
        ->and(fn () => pricingInsert('price_list_channels', ['price_list_id' => Uuid7::generate(), 'channel_id' => $channel]))
        ->toThrow(QueryException::class, 'cmz_price_list_channels_price_list_id_fk');
});

it('checks the quantity step, the amounts and the window of a price', function (): void {
    $list = pricingList();
    $price = pricingPrice($list);
    pricingInsert('prices', $price);

    expect(fn () => pricingInsert('prices', pricingPrice($list, ['min_quantity' => 0])))
        ->toThrow(QueryException::class, 'cmz_prices_min_quantity_positive_ck')
        ->and(fn () => pricingInsert('prices', pricingPrice($list, ['sellable_ref' => 'variant:2', 'amount_minor' => -1])))
        ->toThrow(QueryException::class, 'cmz_prices_amount_non_negative_ck')
        ->and(fn () => pricingInsert('prices', pricingPrice($list, ['sellable_ref' => 'variant:2', 'compare_at_minor' => 1000])))
        ->toThrow(QueryException::class, 'cmz_prices_compare_at_above_amount_ck')
        ->and(fn () => pricingInsert('prices', pricingPrice($list, ['sellable_ref' => 'variant:2', 'valid_from' => '2026-02-01', 'valid_until' => '2026-02-01'])))
        ->toThrow(QueryException::class, 'cmz_prices_window_ordered_ck')
        ->and(fn () => pricingInsert('prices', pricingPrice(Uuid7::generate())))
        ->toThrow(QueryException::class, 'cmz_prices_price_list_id_fk');

    pricingInsert('prices', pricingPrice($list, ['sellable_ref' => 'variant:2', 'compare_at_minor' => 1001, 'valid_from' => '2026-02-01', 'valid_until' => '2026-03-01']));

    $row = DB::table(pricingTable('prices'))->where('id', $price['id'])->first();
    expect($row->min_quantity)->toBe(1)->and($row->customer_group)->toBeNull()->and($row->amount_minor)->toBe(1000);
});

it('refuses to delete a price list that has prices', function (): void {
    $list = pricingList();
    pricingInsert('prices', pricingPrice($list));

    expect(fn () => DB::transaction(fn () => DB::table(pricingTable('price_lists'))->where('id', $list)->delete()))
        ->toThrow(QueryException::class, 'cmz_prices_price_list_id_fk');
});

it('never lets the windows of one price overlap (PRC-01)', function (): void {
    $list = pricingList();
    $window = static fn (?string $from, ?string $until, array $extra = []): array => pricingPrice($list, ['valid_from' => $from, 'valid_until' => $until, ...$extra]);
    $insert = static fn (array $row): Closure => static fn () => pricingInsert('prices', $row);

    pricingInsert('prices', $window('2026-01-01', '2026-02-01'));

    expect(pricingSqlState($insert($window('2026-01-15', '2026-03-01'))))->toBe('23P01') // overlaps the tail
        ->and(pricingSqlState($insert($window('2025-12-01', '2026-01-02'))))->toBe('23P01') // overlaps the head
        ->and(pricingSqlState($insert($window('2026-01-10', '2026-01-20'))))->toBe('23P01') // inside
        ->and(pricingSqlState($insert($window(null, null))))->toBe('23P01') // open both ways
        ->and(pricingSqlState($insert($window('2026-02-01', '2026-03-01'))))->toBeNull() // adjacent: [a, b) and [b, c)
        ->and(pricingSqlState($insert($window('2025-12-01', '2026-01-01'))))->toBeNull()
        ->and(DB::table(pricingTable('prices'))->count())->toBe(3);
});

it('keeps price windows apart by list, sellable, customer group and quantity step', function (): void {
    $list = pricingList();
    $other = pricingList('wholesale');
    $row = static fn (string $list, array $extra = []): array => pricingPrice($list, $extra);

    pricingInsert('prices', $row($list));

    foreach ([
        'another list' => $row($other),
        'another sellable' => $row($list, ['sellable_ref' => 'variant:2']),
        'a group' => $row($list, ['customer_group' => 'vip']),
        'a quantity step' => $row($list, ['min_quantity' => 10]),
    ] as $case => $price) {
        expect(pricingSqlState(static fn () => pricingInsert('prices', $price)))->toBeNull($case);
    }

    expect(pricingSqlState(static fn () => pricingInsert('prices', $row($list, ['customer_group' => 'vip']))))->toBe('23P01') // same group
        ->and(pricingSqlState(static fn () => pricingInsert('prices', $row($list, ['min_quantity' => 10]))))->toBe('23P01')
        ->and(pricingSqlState(static fn () => pricingInsert('prices', $row($list))))->toBe('23P01');
});

it('treats the missing customer group as one group, not as a distinct value per row', function (): void {
    $list = pricingList();

    pricingInsert('prices', pricingPrice($list, ['customer_group' => null, 'valid_from' => '2026-01-01', 'valid_until' => '2026-06-01']));

    expect(pricingSqlState(static fn () => pricingInsert('prices', pricingPrice($list, ['customer_group' => null, 'valid_from' => '2026-03-01', 'valid_until' => '2026-04-01']))))->toBe('23P01')
        ->and(pricingSqlState(static fn () => pricingInsert('prices', pricingPrice($list, ['customer_group' => 'vip', 'valid_from' => '2026-03-01', 'valid_until' => '2026-04-01']))))->toBeNull();
});

it('refuses to move a price window onto another of the same key', function (): void {
    $list = pricingList();
    $first = pricingPrice($list, ['valid_from' => '2026-01-01', 'valid_until' => '2026-02-01']);
    $second = pricingPrice($list, ['valid_from' => '2026-02-01', 'valid_until' => '2026-03-01']);
    pricingInsert('prices', $first);
    pricingInsert('prices', $second);

    expect(pricingSqlState(static fn () => pricingUpdate('prices', $second['id'], ['valid_from' => '2026-01-15'])))->toBe('23P01')
        ->and(pricingSqlState(static fn () => pricingUpdate('prices', $second['id'], ['valid_from' => '2026-02-01', 'valid_until' => null])))->toBeNull();
});

it('keeps tax class and zone codes unique', function (): void {
    pricingClass();
    pricingZone();

    expect(fn () => pricingClass())->toThrow(UniqueConstraintViolationException::class, 'cmz_tax_classes_code_uq')
        ->and(fn () => pricingZone())->toThrow(UniqueConstraintViolationException::class, 'cmz_tax_zones_code_uq');
});

it('gives a country and region pair to one zone only, a missing region counting as a value (TAX-01)', function (): void {
    $ru = pricingZone();
    $eu = pricingZone('eu');
    $member = static fn (string $zone, string $country, ?string $region = null): array => ['tax_zone_id' => $zone, 'country' => $country, 'region' => $region];

    pricingInsert('tax_zone_members', $member($ru, 'RU'));
    pricingInsert('tax_zone_members', $member($ru, 'RU', 'RU-MOW'));
    pricingInsert('tax_zone_members', $member($eu, 'DE'));

    $unique = ConstraintNames::unique(pricingTable('tax_zone_members'), ['country', 'region']);

    expect(fn () => pricingInsert('tax_zone_members', $member($eu, 'RU')))
        ->toThrow(UniqueConstraintViolationException::class, $unique)
        ->and(fn () => pricingInsert('tax_zone_members', $member($ru, 'RU')))
        ->toThrow(UniqueConstraintViolationException::class, $unique)
        ->and(fn () => pricingInsert('tax_zone_members', $member($eu, 'RU', 'RU-MOW')))
        ->toThrow(UniqueConstraintViolationException::class, $unique)
        ->and(fn () => pricingInsert('tax_zone_members', $member($eu, 'ru')))
        ->toThrow(QueryException::class, 'cmz_tax_zone_members_country_format_ck')
        ->and(fn () => pricingInsert('tax_zone_members', $member(Uuid7::generate(), 'FR')))
        ->toThrow(QueryException::class, 'cmz_tax_zone_members_tax_zone_id_fk');

    pricingInsert('tax_zone_members', $member($eu, 'RU', 'RU-SPE')); // another region of a known country

    expect(DB::table(pricingTable('tax_zone_members'))->count())->toBe(4);
});

it('removes zone members with their zone', function (): void {
    $zone = pricingZone();
    pricingInsert('tax_zone_members', ['tax_zone_id' => $zone, 'country' => 'RU']);

    DB::table(pricingTable('tax_zones'))->where('id', $zone)->delete();

    expect(DB::table(pricingTable('tax_zone_members'))->count())->toBe(0);
});

it('checks the range and window of a tax rate and defaults its code to vat', function (): void {
    $class = pricingClass();
    $zone = pricingZone();
    $rate = pricingRate($class, $zone);
    pricingInsert('tax_rates', $rate);

    expect(fn () => pricingInsert('tax_rates', pricingRate($class, $zone, ['code' => 'gst', 'rate_bp' => 10001])))
        ->toThrow(QueryException::class, 'cmz_tax_rates_rate_bp_range_ck')
        ->and(fn () => pricingInsert('tax_rates', pricingRate($class, $zone, ['code' => 'gst', 'rate_bp' => -1])))
        ->toThrow(QueryException::class, 'cmz_tax_rates_rate_bp_range_ck')
        ->and(fn () => pricingInsert('tax_rates', pricingRate($class, $zone, ['code' => 'gst', 'valid_from' => '2026-02-01', 'valid_until' => '2026-01-01'])))
        ->toThrow(QueryException::class, 'cmz_tax_rates_window_ordered_ck')
        ->and(fn () => pricingInsert('tax_rates', pricingRate(Uuid7::generate(), $zone, ['code' => 'gst'])))
        ->toThrow(QueryException::class, 'cmz_tax_rates_tax_class_id_fk')
        ->and(fn () => DB::transaction(fn () => DB::table(pricingTable('tax_zones'))->where('id', $zone)->delete()))
        ->toThrow(QueryException::class, 'cmz_tax_rates_tax_zone_id_fk');

    pricingInsert('tax_rates', pricingRate($class, $zone, ['code' => 'zero', 'rate_bp' => 0]));
    pricingInsert('tax_rates', pricingRate($class, $zone, ['code' => 'full', 'rate_bp' => 10000]));

    expect(DB::table(pricingTable('tax_rates'))->where('id', $rate['id'])->value('code'))->toBe('vat');
});

it('never lets the windows of one tax rate overlap, while other codes, classes and zones may (TAX-01)', function (): void {
    $standard = pricingClass();
    $reduced = pricingClass('reduced');
    $ru = pricingZone();
    $eu = pricingZone('eu');
    $window = static fn (?string $from, ?string $until, array $extra = []): array => pricingRate($standard, $ru, ['valid_from' => $from, 'valid_until' => $until, ...$extra]);
    $state = static fn (array $row): ?string => pricingSqlState(static fn () => pricingInsert('tax_rates', $row));

    pricingInsert('tax_rates', $window(null, '2026-01-01'));
    pricingInsert('tax_rates', $window('2026-01-01', null, ['rate_bp' => 2200]));

    expect($state($window('2025-06-01', '2026-06-01')))->toBe('23P01')
        ->and($state($window(null, null)))->toBe('23P01')
        ->and($state($window('2030-01-01', '2031-01-01')))->toBe('23P01') // the open-ended window
        ->and($state($window('2025-01-01', '2025-06-01', ['code' => 'gst'])))->toBeNull()
        ->and($state(pricingRate($reduced, $ru)))->toBeNull()
        ->and($state(pricingRate($standard, $eu)))->toBeNull();
});

it('drops and recreates the whole pricing schema on rollback and migrate', function (): void {
    $options = ['--path' => realpath(__DIR__.'/../../database/migrations'), '--realpath' => true];

    $this->artisan('migrate:rollback', $options)->assertExitCode(0);

    foreach (PRICING_TABLES as $logical) {
        expect(DB::getSchemaBuilder()->hasTable(pricingTable($logical)))->toBeFalse($logical);
    }

    expect(DB::selectOne("select count(*) as n from pg_extension where extname = 'btree_gist'")->n)->toBe(1); // shared: down() keeps it

    $this->artisan('migrate', $options)->assertExitCode(0);

    foreach (PRICING_TABLES as $logical) {
        expect(DB::getSchemaBuilder()->hasTable(pricingTable($logical)))->toBeTrue($logical);
    }
});
