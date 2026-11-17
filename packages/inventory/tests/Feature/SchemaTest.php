<?php

declare(strict_types=1);

use Commerz\Identity\Uuid7;
use Commerz\Inventory\InventoryServiceProvider;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Packman\Schema\Contracts\Compiler\SchemaOwnership;
use Packman\Schema\Data\Compiler\ConstraintNames;
use Packman\Schema\Data\Compiler\TableNames;

uses(RefreshDatabase::class);

const STOCK_TABLES = [
    'stock_locations', 'stock_items', 'stock_item_links', 'stock_sources', 'stock_levels', 'stock_movements',
    'stock_reservations', 'stock_reservation_lines', 'stock_transfers', 'stock_transfer_lines',
];

function stockTable(string $logical): string
{
    return app(TableNames::class)->table($logical);
}

/** @return list<string> */
function stockConstraints(string $logical): array
{
    return array_map(
        static fn (object $row): string => $row->conname,
        DB::select('select conname from pg_constraint where conrelid = ?::regclass order by conname', [stockTable($logical)]),
    );
}

// RefreshDatabase wraps each test in a transaction; a savepoint keeps it usable after an expected violation.

/** @param  array<string, mixed>  $row */
function stockInsert(string $logical, array $row): void
{
    DB::transaction(static fn () => DB::table(stockTable($logical))->insert($row));
}

/** @param  array<string, mixed>  $set */
function stockUpdate(string $logical, string $id, array $set): void
{
    DB::transaction(static fn () => DB::table(stockTable($logical))->where('id', $id)->update($set));
}

/** @return array{location: string, east: string, item: string, level: string} */
function stockSeed(): array
{
    $ids = ['location' => Uuid7::generate(), 'east' => Uuid7::generate(), 'item' => Uuid7::generate(), 'level' => Uuid7::generate()];

    stockInsert('stock_locations', ['id' => $ids['location'], 'code' => 'main', 'name' => 'Main', 'kind' => 'warehouse']);
    stockInsert('stock_locations', ['id' => $ids['east'], 'code' => 'east', 'name' => 'East', 'kind' => 'store']);
    stockInsert('stock_items', ['id' => $ids['item'], 'sku' => 'TEE-RED', 'unit' => 'pcs']);
    stockInsert('stock_levels', ['id' => $ids['level'], 'stock_item_id' => $ids['item'], 'location_id' => $ids['location'], 'on_hand' => 10]);

    return $ids;
}

/** @return array<string, mixed> */
function stockReservation(string $demand = 'order:1', array $extra = []): array
{
    return ['id' => Uuid7::generate(), 'demand' => $demand, 'state' => 'active', ...$extra];
}

/** @return array<string, mixed> */
function stockLine(array $ids, string $reservation, array $extra = []): array
{
    return [
        'id' => Uuid7::generate(), 'reservation_id' => $reservation, 'sellable_ref' => 'variant:1', 'stock_item_id' => $ids['item'],
        'location_id' => $ids['location'], 'stock_level_id' => $ids['level'], 'generation' => 1, 'quantity_per_unit' => 1,
        'units' => 5, 'position' => 0, ...$extra,
    ];
}

it('declares the stock noun under commerz.inventory', function (): void {
    $owners = app(SchemaOwnership::class)->owners();

    expect($owners['commerz.inventory'])->toBe(InventoryServiceProvider::NOUNS)
        ->and(app(SchemaOwnership::class)->ownerOf('stock'))->toBe('commerz.inventory');
});

it('creates every table with canonical constraint names', function (): void {
    foreach (STOCK_TABLES as $logical) {
        $physical = stockTable($logical);

        expect(DB::getSchemaBuilder()->hasTable($physical))->toBeTrue("table {$physical}");

        foreach (stockConstraints($logical) as $name) {
            expect($name)->toMatch('/(_(pk|uq|fk|ck)|_[0-9a-f]{8})\z/'); // a name over 63 bytes ends in the hash of ConstraintNames::shorten
        }

        foreach (DB::select('select indexname from pg_indexes where tablename = ?', [$physical]) as $index) {
            expect($index->indexname)->toMatch('/(_(pk|uq|ix)|_[0-9a-f]{8})\z/');
        }

        expect(stockConstraints($logical))->toContain(ConstraintNames::primary($physical));
    }
});

it('keeps quantities in bigint and ids as uuid', function (): void {
    $columns = DB::select(
        "select table_name, column_name, data_type from information_schema.columns where table_name like 'cmz\\_stock\\_%' and (column_name in ('on_hand', 'reserved', 'safety_stock', 'version', 'quantity_per_unit', 'units', 'consumed_units', 'released_units', 'expired_units', 'delta', 'balance_after', 'quantity', 'received', 'lost'))",
    );

    expect($columns)->not->toBeEmpty();

    foreach ($columns as $column) {
        expect($column->data_type)->toBe('bigint', "{$column->table_name}.{$column->column_name}");
    }

    expect(DB::selectOne("select data_type from information_schema.columns where table_name = ? and column_name = 'id'", [stockTable('stock_movements')])->data_type)->toBe('bigint')
        ->and(DB::selectOne("select data_type from information_schema.columns where table_name = ? and column_name = 'channel_id'", [stockTable('stock_sources')])->data_type)->toBe('uuid');
});

it('has no foreign key to the core or other packages', function (): void {
    foreach (STOCK_TABLES as $logical) {
        $targets = DB::select(
            "select confrelid::regclass::text as target from pg_constraint where conrelid = ?::regclass and contype = 'f'",
            [stockTable($logical)],
        );

        $prefix = substr(stockTable('stock_locations'), 0, -strlen('stock_locations'));

        foreach ($targets as $row) {
            expect($row->target)->toStartWith($prefix.'stock_', "{$logical} references {$row->target}");
        }
    }
});

it('keeps location codes and skus unique and checks their enums', function (): void {
    $ids = stockSeed();

    expect(fn () => stockInsert('stock_locations', ['id' => Uuid7::generate(), 'code' => 'main', 'name' => 'Dup', 'kind' => 'store']))
        ->toThrow(UniqueConstraintViolationException::class, 'cmz_stock_locations_code_uq')
        ->and(fn () => stockInsert('stock_locations', ['id' => Uuid7::generate(), 'code' => 'x', 'name' => 'X', 'kind' => 'depot']))
        ->toThrow(QueryException::class, 'cmz_stock_locations_kind_valid_ck')
        ->and(fn () => stockUpdate('stock_locations', $ids['location'], ['status' => 'deleted']))
        ->toThrow(QueryException::class, 'cmz_stock_locations_status_valid_ck')
        ->and(fn () => stockInsert('stock_items', ['id' => Uuid7::generate(), 'sku' => 'TEE-RED', 'unit' => 'pcs']))
        ->toThrow(UniqueConstraintViolationException::class, 'cmz_stock_items_sku_uq')
        ->and(fn () => stockUpdate('stock_items', $ids['item'], ['policy' => 'unlimited']))
        ->toThrow(QueryException::class, 'cmz_stock_items_policy_valid_ck')
        ->and(fn () => stockUpdate('stock_items', $ids['item'], ['status' => 'gone']))
        ->toThrow(QueryException::class, 'cmz_stock_items_status_valid_ck');

    $defaults = DB::table(stockTable('stock_items'))->where('id', $ids['item'])->first();
    expect($defaults->policy)->toBe('tracked')->and($defaults->status)->toBe('active');
});

it('links a sellable to an item once with a positive quantity and shares an item between sellables', function (): void {
    $ids = stockSeed();
    $link = static fn (string $sellable, int $quantity = 1): array => [
        'id' => Uuid7::generate(), 'sellable_ref' => $sellable, 'stock_item_id' => $ids['item'], 'quantity_per_unit' => $quantity,
    ];

    stockInsert('stock_item_links', $link('variant:1'));
    stockInsert('stock_item_links', $link('variant:2', 3));

    expect(fn () => stockInsert('stock_item_links', $link('variant:1')))
        ->toThrow(UniqueConstraintViolationException::class, 'cmz_stock_item_links_sellable_ref_stock_item_id_uq')
        ->and(fn () => stockInsert('stock_item_links', $link('variant:3', 0)))
        ->toThrow(QueryException::class, 'cmz_stock_item_links_quantity_per_unit_positive_ck')
        ->and(fn () => stockInsert('stock_item_links', [...$link('variant:4'), 'stock_item_id' => Uuid7::generate()]))
        ->toThrow(QueryException::class, 'cmz_stock_item_links_stock_item_id_fk');
});

it('has one source per channel and location with a bounded priority and no channel foreign key', function (): void {
    $ids = stockSeed();
    $channel = Uuid7::generate(); // not a real channel: sources carry no foreign key to the core table
    $source = static fn (string $location, int $priority, ?string $channelId = null): array => [
        'id' => Uuid7::generate(), 'channel_id' => $channelId ?? $channel, 'location_id' => $location, 'priority' => $priority,
    ];

    stockInsert('stock_sources', $source($ids['location'], 0));
    stockInsert('stock_sources', $source($ids['east'], 1_000_000));
    stockInsert('stock_sources', $source($ids['location'], 5, Uuid7::generate()));

    expect(fn () => stockInsert('stock_sources', $source($ids['location'], 1)))
        ->toThrow(UniqueConstraintViolationException::class, 'cmz_stock_sources_channel_id_location_id_uq')
        ->and(fn () => stockInsert('stock_sources', $source($ids['east'], -1, Uuid7::generate())))
        ->toThrow(QueryException::class, 'cmz_stock_sources_priority_range_ck')
        ->and(fn () => stockInsert('stock_sources', $source($ids['east'], 1_000_001, Uuid7::generate())))
        ->toThrow(QueryException::class, 'cmz_stock_sources_priority_range_ck')
        ->and(fn () => stockInsert('stock_sources', $source(Uuid7::generate(), 1, Uuid7::generate())))
        ->toThrow(QueryException::class, 'cmz_stock_sources_location_id_fk');
});

it('keeps one level per item and location and never lets a quantity go negative', function (): void {
    $ids = stockSeed();

    expect(fn () => stockInsert('stock_levels', ['id' => Uuid7::generate(), 'stock_item_id' => $ids['item'], 'location_id' => $ids['location']]))
        ->toThrow(UniqueConstraintViolationException::class, 'cmz_stock_levels_stock_item_id_location_id_uq')
        ->and(fn () => stockUpdate('stock_levels', $ids['level'], ['on_hand' => -1]))
        ->toThrow(QueryException::class, 'cmz_stock_levels_on_hand_non_negative_ck')
        ->and(fn () => stockUpdate('stock_levels', $ids['level'], ['reserved' => -1]))
        ->toThrow(QueryException::class, 'cmz_stock_levels_reserved_non_negative_ck')
        ->and(fn () => stockUpdate('stock_levels', $ids['level'], ['safety_stock' => -1]))
        ->toThrow(QueryException::class, 'cmz_stock_levels_safety_stock_non_negative_ck');

    stockUpdate('stock_levels', $ids['level'], ['on_hand' => PHP_INT_MAX, 'reserved' => PHP_INT_MAX]);

    $level = DB::table(stockTable('stock_levels'))->where('id', $ids['level'])->first();
    expect($level->on_hand)->toBe(PHP_INT_MAX)->and($level->version)->toBe(0);
});

it('journals movements append-only with a non-zero delta and a known kind', function (): void {
    $ids = stockSeed();
    $movement = static fn (array $extra = []): array => [
        'stock_level_id' => $ids['level'], 'stock_item_id' => $ids['item'], 'location_id' => $ids['location'],
        'kind' => 'receipt', 'delta' => 10, 'balance_after' => 10, ...$extra,
    ];

    stockInsert('stock_movements', $movement());
    stockInsert('stock_movements', $movement(['kind' => 'sale', 'delta' => -4, 'balance_after' => 6, 'reference' => 'fulfillment:1']));

    expect(fn () => stockInsert('stock_movements', $movement(['delta' => 0])))
        ->toThrow(QueryException::class, 'cmz_stock_movements_delta_non_zero_ck')
        ->and(fn () => stockInsert('stock_movements', $movement(['balance_after' => -1])))
        ->toThrow(QueryException::class, 'cmz_stock_movements_balance_after_non_negative_ck')
        ->and(fn () => stockInsert('stock_movements', $movement(['kind' => 'theft'])))
        ->toThrow(QueryException::class, 'cmz_stock_movements_kind_valid_ck')
        ->and(fn () => stockInsert('stock_movements', $movement(['id' => 99])))
        ->toThrow(QueryException::class);

    $rows = DB::table(stockTable('stock_movements'))->orderBy('id')->get();
    expect($rows)->toHaveCount(2)->and($rows[1]->id)->toBeGreaterThan($rows[0]->id);
});

it('holds one reservation per demand and checks its state', function (): void {
    stockSeed();
    $reservation = stockReservation();

    stockInsert('stock_reservations', $reservation);
    stockInsert('stock_reservations', stockReservation('transfer:1', ['channel_id' => null]));

    expect(fn () => stockInsert('stock_reservations', stockReservation('order:1')))
        ->toThrow(UniqueConstraintViolationException::class, 'cmz_stock_reservations_demand_uq')
        ->and(fn () => stockUpdate('stock_reservations', $reservation['id'], ['state' => 'expired']))
        ->toThrow(QueryException::class, 'cmz_stock_reservations_state_valid_ck');

    $row = DB::table(stockTable('stock_reservations'))->where('id', $reservation['id'])->first();
    expect($row->generation)->toBe(1);
});

it('indexes only active reservations that expire', function (): void {
    $definition = DB::selectOne('select indexdef from pg_indexes where indexname = ?', ['cmz_stock_reservations_expires_at_ix']);

    expect($definition->indexdef)->toContain('expires_at')->toContain("state)::text = 'active'")->toContain('expires_at IS NOT NULL');
});

it('keeps the counters of a reservation line within its units', function (): void {
    $ids = stockSeed();
    $reservation = stockReservation();
    stockInsert('stock_reservations', $reservation);
    $line = stockLine($ids, $reservation['id']);
    stockInsert('stock_reservation_lines', $line);

    expect(fn () => stockUpdate('stock_reservation_lines', $line['id'], ['units' => 0]))
        ->toThrow(QueryException::class, 'cmz_stock_reservation_lines_units_positive_ck')
        ->and(fn () => stockUpdate('stock_reservation_lines', $line['id'], ['quantity_per_unit' => 0]))
        ->toThrow(QueryException::class, 'cmz_stock_reservation_lines_units_positive_ck')
        ->and(fn () => stockUpdate('stock_reservation_lines', $line['id'], ['consumed_units' => -1]))
        ->toThrow(QueryException::class, 'cmz_stock_reservation_lines_counters_non_negative_ck')
        ->and(fn () => stockUpdate('stock_reservation_lines', $line['id'], ['consumed_units' => 3, 'released_units' => 2, 'expired_units' => 1]))
        ->toThrow(QueryException::class, 'cmz_stock_reservation_lines_counters_within_units_ck');

    stockUpdate('stock_reservation_lines', $line['id'], ['consumed_units' => 3, 'released_units' => 1, 'expired_units' => 1]);

    expect(DB::table(stockTable('stock_reservation_lines'))->where('id', $line['id'])->value('consumed_units'))->toBe(3);
});

it('allows a reservation line once per generation and level, treating a missing sellable as one value', function (): void {
    $ids = stockSeed();
    $reservation = stockReservation();
    stockInsert('stock_reservations', $reservation);
    stockInsert('stock_reservation_lines', stockLine($ids, $reservation['id']));
    stockInsert('stock_reservation_lines', stockLine($ids, $reservation['id'], ['generation' => 2]));
    stockInsert('stock_reservation_lines', stockLine($ids, $reservation['id'], ['sellable_ref' => 'variant:2']));
    stockInsert('stock_reservation_lines', stockLine($ids, $reservation['id'], ['sellable_ref' => null]));

    expect(fn () => stockInsert('stock_reservation_lines', stockLine($ids, $reservation['id'])))
        ->toThrow(UniqueConstraintViolationException::class, ConstraintNames::unique(stockTable('stock_reservation_lines'), ['reservation_id', 'generation', 'sellable_ref', 'stock_level_id']))
        ->and(fn () => stockInsert('stock_reservation_lines', stockLine($ids, $reservation['id'], ['sellable_ref' => null])))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('removes lines with their reservation but restricts deleting a level or item they use', function (): void {
    $ids = stockSeed();
    $reservation = stockReservation();
    stockInsert('stock_reservations', $reservation);
    stockInsert('stock_reservation_lines', stockLine($ids, $reservation['id']));

    expect(fn () => DB::transaction(fn () => DB::table(stockTable('stock_levels'))->where('id', $ids['level'])->delete()))
        ->toThrow(QueryException::class, 'cmz_stock_reservation_lines_stock_level_id_fk')
        ->and(fn () => DB::transaction(fn () => DB::table(stockTable('stock_items'))->where('id', $ids['item'])->delete()))
        ->toThrow(QueryException::class);

    DB::table(stockTable('stock_reservations'))->where('id', $reservation['id'])->delete();

    expect(DB::table(stockTable('stock_reservation_lines'))->count())->toBe(0);
});

it('indexes only outstanding reservation lines per level', function (): void {
    $definition = DB::selectOne('select indexdef from pg_indexes where indexname = ?', ['cmz_stock_reservation_lines_stock_level_id_ix']);

    expect($definition->indexdef)->toContain('stock_level_id')->toContain('consumed_units')->toContain('WHERE');
});

it('moves stock between two different locations in a known state', function (): void {
    $ids = stockSeed();
    $transfer = static fn (string $from, string $to, string $state = 'draft'): array => [
        'id' => Uuid7::generate(), 'from_location_id' => $from, 'to_location_id' => $to, 'state' => $state,
    ];

    stockInsert('stock_transfers', $transfer($ids['location'], $ids['east']));

    expect(fn () => stockInsert('stock_transfers', $transfer($ids['location'], $ids['location'])))
        ->toThrow(QueryException::class, 'cmz_stock_transfers_locations_differ_ck')
        ->and(fn () => stockInsert('stock_transfers', $transfer($ids['location'], $ids['east'], 'lost')))
        ->toThrow(QueryException::class, 'cmz_stock_transfers_state_valid_ck');
});

it('keeps a transfer line once per item with counters within its quantity', function (): void {
    $ids = stockSeed();
    $transfer = ['id' => Uuid7::generate(), 'from_location_id' => $ids['location'], 'to_location_id' => $ids['east'], 'state' => 'draft'];
    stockInsert('stock_transfers', $transfer);
    $line = ['id' => Uuid7::generate(), 'transfer_id' => $transfer['id'], 'stock_item_id' => $ids['item'], 'quantity' => 5, 'position' => 0];
    stockInsert('stock_transfer_lines', $line);

    expect(fn () => stockInsert('stock_transfer_lines', [...$line, 'id' => Uuid7::generate()]))
        ->toThrow(UniqueConstraintViolationException::class, 'cmz_stock_transfer_lines_transfer_id_stock_item_id_uq')
        ->and(fn () => stockUpdate('stock_transfer_lines', $line['id'], ['quantity' => 0]))
        ->toThrow(QueryException::class)
        ->and(fn () => stockUpdate('stock_transfer_lines', $line['id'], ['received' => 4, 'lost' => 2]))
        ->toThrow(QueryException::class, 'cmz_stock_transfer_lines_counters_within_quantity_ck');

    stockUpdate('stock_transfer_lines', $line['id'], ['received' => 4, 'lost' => 1]);
    DB::table(stockTable('stock_transfers'))->where('id', $transfer['id'])->delete();

    expect(DB::table(stockTable('stock_transfer_lines'))->count())->toBe(0);
});

it('drops and recreates the whole stock schema on rollback and migrate', function (): void {
    $options = ['--path' => realpath(__DIR__.'/../../database/migrations'), '--realpath' => true];

    $this->artisan('migrate:rollback', $options)->assertExitCode(0);

    foreach (STOCK_TABLES as $logical) {
        expect(DB::getSchemaBuilder()->hasTable(stockTable($logical)))->toBeFalse($logical);
    }

    $this->artisan('migrate', $options)->assertExitCode(0);

    foreach (STOCK_TABLES as $logical) {
        expect(DB::getSchemaBuilder()->hasTable(stockTable($logical)))->toBeTrue($logical);
    }
});
