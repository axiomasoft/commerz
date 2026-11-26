<?php

declare(strict_types=1);

use Commerz\Configuration\CommerzOptions;
use Commerz\Errors\IdempotencyConflict;
use Commerz\Errors\InvalidCommand;
use Commerz\Identity\ActorRef;
use Commerz\Identity\SellableRef;
use Commerz\Inventory\Facades\Inventory;
use Commerz\Inventory\Items\Data\CreateStockItem;
use Commerz\Inventory\Items\Data\LinkSellable;
use Commerz\Inventory\Items\Data\StockItemId;
use Commerz\Inventory\Items\Enums\StockPolicy;
use Commerz\Inventory\Locations\Data\CreateLocation;
use Commerz\Inventory\Movements\Data\ListMovements;
use Commerz\Inventory\Sources\Data\AssignSource;
use Commerz\Inventory\Stock\Data\AdjustStock;
use Commerz\Inventory\Stock\Data\ReceiveStock;
use Commerz\Inventory\Stock\Enums\MovementKind;
use Commerz\Money\Currency;
use Commerz\Ports\Stock\Contracts\StockReturns;
use Commerz\Ports\Stock\Data\RestockLine;
use Commerz\Ports\Stock\Data\RestockStock;
use Commerz\Ports\Stock\Data\WriteOffLine;
use Commerz\Ports\Stock\Data\WriteOffStock;
use Commerz\Schema\TableNames;
use Commerz\Stores\Contracts\Channels;
use Commerz\Stores\Contracts\Stores;
use Commerz\Stores\Data\ChannelId;
use Commerz\Stores\Data\CreateChannel;
use Commerz\Stores\Data\CreateStore;
use Illuminate\Database\Connection;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\DB;

final class InvWoSqliteConnection extends SQLiteConnection
{
    private const array BOOL_COLUMNS = ['prices_include_tax', 'requires_shipping', 'tax_included', 'booking_requested'];

    private static function normalizePgSql(string $query): string
    {
        $normalized = str_replace(['::jsonb', '::text'], '', $query);

        return (string) preg_replace('/\s+for\s+(?:update|share)(?:\s+nowait|\s+skip\s+locked)?\b/i', '', $normalized);
    }

    public function statement($query, $bindings = []): bool
    {
        if (stripos(ltrim($query), 'set local lock_timeout') === 0) {
            return true;
        }

        return parent::statement(self::normalizePgSql($query), $bindings);
    }

    public function affectingStatement($query, $bindings = []): int
    {
        return parent::affectingStatement(self::normalizePgSql($query), $bindings);
    }

    public function select($query, $bindings = [], $useReadPdo = true, array $fetchUsing = []): array
    {
        $rows = parent::select(self::normalizePgSql($query), $bindings, $useReadPdo, $fetchUsing);

        foreach ($rows as $row) {
            if (! is_object($row)) {
                continue;
            }

            foreach (self::BOOL_COLUMNS as $col) {
                if (property_exists($row, $col) && is_int($row->{$col})) {
                    $row->{$col} = $row->{$col} !== 0;
                }
            }
        }

        return $rows;
    }
}

beforeEach(function (): void {
    Connection::resolverFor('sqlite', static function ($connection, $database, $prefix, array $config) {
        if (($config['commerz_compat'] ?? false) === true) {
            return new InvWoSqliteConnection($connection, $database, $prefix, $config);
        }

        return new SQLiteConnection($connection, $database, $prefix, $config);
    });

    config()->set('database.connections.inv_wo_test', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'commerz_compat' => true,
    ]);
    config()->set('commerz.connection', 'inv_wo_test');
    DB::purge('inv_wo_test');

    foreach ([CommerzOptions::class, TableNames::class, Stores::class, Channels::class, StockReturns::class] as $service) {
        app()->forgetInstance($service);
    }

    $db = DB::connection('inv_wo_test');
    $db->statement('PRAGMA foreign_keys = ON');
    $pdo = $db->getPdo();
    $pdo->sqliteCreateFunction('hashtext', static fn (string $key): int => crc32($key));
    $pdo->sqliteCreateFunction('pg_advisory_xact_lock', static fn (int $_): int => 1);

    $db->statement(<<<'SQL'
        create table cmz_operation_receipts (
            scope text not null,
            key text not null,
            fingerprint text not null,
            result text,
            created_at text not null default current_timestamp,
            primary key (scope, key)
        )
    SQL);

    $db->statement(<<<'SQL'
        create table cmz_outbox_messages (
            id integer primary key autoincrement,
            event_id text not null unique,
            type text not null,
            version integer not null default 1,
            aggregate_ref text not null,
            payload text not null,
            occurred_at text not null,
            available_at text not null default current_timestamp,
            claimed_at text,
            delivered_at text,
            attempts integer not null default 0,
            last_error text
        )
    SQL);

    $db->statement(<<<'SQL'
        create table cmz_stores (
            id text primary key,
            code text not null unique,
            name text not null,
            default_currency text not null,
            order_number_prefix text not null default '',
            status text not null default 'active',
            created_at text not null,
            updated_at text not null
        )
    SQL);

    $db->statement(<<<'SQL'
        create table cmz_channels (
            id text primary key,
            store_id text not null,
            code text not null unique,
            name text not null,
            currencies text not null,
            default_currency text not null,
            locales text not null,
            default_locale text not null default 'en',
            prices_include_tax integer not null default 0,
            status text not null default 'active',
            created_at text not null,
            updated_at text not null
        )
    SQL);

    $db->statement(<<<'SQL'
        create table cmz_stock_locations (
            id text primary key,
            code text not null unique,
            name text not null,
            kind text not null default 'warehouse',
            status text not null default 'active',
            created_at text not null,
            updated_at text not null
        )
    SQL);

    $db->statement(<<<'SQL'
        create table cmz_stock_items (
            id text primary key,
            sku text not null unique,
            name text,
            unit text not null default 'pcs',
            policy text not null default 'tracked',
            status text not null default 'active',
            hs_code text,
            origin_country_code text,
            material text,
            customs_description text,
            created_at text not null,
            updated_at text not null
        )
    SQL);

    $db->statement(<<<'SQL'
        create table cmz_stock_item_links (
            id text primary key,
            sellable_ref text not null,
            stock_item_id text not null references cmz_stock_items(id),
            quantity_per_unit integer not null check (quantity_per_unit >= 1),
            created_at text not null,
            updated_at text not null,
            unique (sellable_ref, stock_item_id)
        )
    SQL);

    $db->statement(<<<'SQL'
        create table cmz_stock_sources (
            id text primary key,
            channel_id text not null,
            location_id text not null references cmz_stock_locations(id),
            priority integer not null default 0,
            created_at text not null,
            updated_at text not null,
            unique (channel_id, location_id)
        )
    SQL);

    $db->statement(<<<'SQL'
        create table cmz_stock_levels (
            id text primary key,
            stock_item_id text not null references cmz_stock_items(id),
            location_id text not null references cmz_stock_locations(id),
            on_hand integer not null default 0 check (on_hand >= 0),
            reserved integer not null default 0 check (reserved >= 0),
            safety_stock integer not null default 0 check (safety_stock >= 0),
            version integer not null default 0,
            created_at text not null,
            updated_at text not null,
            unique (stock_item_id, location_id)
        )
    SQL);

    $db->statement(<<<'SQL'
        create table cmz_stock_movements (
            id integer primary key autoincrement,
            stock_level_id text not null references cmz_stock_levels(id),
            stock_item_id text not null,
            location_id text not null,
            kind text not null check (kind in ('receipt', 'adjustment', 'count', 'sale', 'transfer_out', 'transfer_in', 'return', 'write_off')),
            delta integer not null check (delta <> 0),
            balance_after integer not null check (balance_after >= 0),
            reference text,
            reason text,
            actor_ref text,
            occurred_at text not null,
            check (kind <> 'write_off' or delta < 0)
        )
    SQL);

    // Keep an ambient transaction open so DatabaseTransactions::run uses savepoints without PG `set local lock_timeout`
    $db->beginTransaction();
});

afterEach(function (): void {
    $db = DB::connection('inv_wo_test');
    while ($db->transactionLevel() > 0) {
        $db->rollBack();
    }
});

/** @return array{ChannelId, SellableRef, array<string, StockItemId>} */
function woSeedKit(): array
{
    $store = app(Stores::class)->create(new CreateStore('main', 'Main', Currency::of('RUB')));
    $channel = app(Channels::class)->create(new CreateChannel($store->id, 'web', 'Web', [Currency::of('RUB')]))->id;

    $wh = Inventory::locations()->create(new CreateLocation('wh-main', 'Main Warehouse'));
    Inventory::sources()->assign(new AssignSource($channel, $wh->id, 10));

    $kit = SellableRef::of('variant', 'coffee-kit');
    $glass = Inventory::items()->create(new CreateStockItem('SKU-CARAFE', policy: StockPolicy::Tracked));
    $filter = Inventory::items()->create(new CreateStockItem('SKU-FILTER', policy: StockPolicy::Tracked));
    $manual = Inventory::items()->create(new CreateStockItem('SKU-LEAFLET', policy: StockPolicy::Untracked));

    Inventory::items()->link(new LinkSellable($kit, $glass->id, 1));
    Inventory::items()->link(new LinkSellable($kit, $filter->id, 2));
    Inventory::items()->link(new LinkSellable($kit, $manual->id, 1));

    Inventory::stock()->receive(new ReceiveStock($glass->id, $wh->id, 10, reference: 'po:1'));
    Inventory::stock()->receive(new ReceiveStock($filter->id, $wh->id, 20, reference: 'po:1'));
    // Simulate 3 kits shipped (-3 carafe -> 7 on hand, -6 filters -> 14 on hand)
    Inventory::stock()->adjust(new AdjustStock($glass->id, $wh->id, -3, 'shipped'));
    Inventory::stock()->adjust(new AdjustStock($filter->id, $wh->id, -6, 'shipped'));

    return [$channel, $kit, ['carafe' => $glass->id, 'filter' => $filter->id, 'manual' => $manual->id]];
}

it('writes a write_off movement to cmz_stock_movements and NEVER increases sellable on_hand (INV-INV-002)', function (): void {
    [$channel, $kit, $items] = woSeedKit();
    $wh = Inventory::locations()->findByCode('wh-main')->id;

    expect(Inventory::stock()->level($items['carafe'], $wh)->onHand)->toBe(7)
        ->and(Inventory::stock()->level($items['filter'], $wh)->onHand)->toBe(14);

    app(StockReturns::class)->writeOff(new WriteOffStock(
        reference: 'return:rma-1:write_off',
        channel: $channel,
        lines: [new WriteOffLine($kit, 2)],
        locationCode: 'wh-main',
        reason: 'damaged_in_transit',
        actor: ActorRef::of('operator', '9'),
    ));

    // INV-INV-002: sellable on_hand NEVER increases (remains 7 and 14, NOT 9 and 18!)
    expect(Inventory::stock()->level($items['carafe'], $wh)->onHand)->toBe(7)
        ->and(Inventory::stock()->level($items['filter'], $wh)->onHand)->toBe(14);

    $movements = Inventory::movements()->list(new ListMovements(reference: 'return:rma-1:write_off'))->items;

    expect($movements)->toHaveCount(2)
        ->and($movements[0]->kind)->toBe(MovementKind::WriteOff)
        ->and($movements[1]->kind)->toBe(MovementKind::WriteOff);

    $byItem = [];
    foreach ($movements as $m) {
        $byItem[$m->item->value] = [$m->delta, $m->balanceAfter, $m->reason, (string) $m->actor];
    }

    expect($byItem[$items['carafe']->value])->toBe([-2, 7, 'damaged_in_transit', 'operator:9'])
        ->and($byItem[$items['filter']->value])->toBe([-4, 14, 'damaged_in_transit', 'operator:9'])
        ->and(DB::connection('inv_wo_test')->table('cmz_stock_levels')->where('stock_item_id', $items['manual']->value)->exists())->toBeFalse();
});

it('keeps on_hand at zero when writing off damaged goods at an empty inspection location and is idempotent per reference', function (): void {
    [$channel, $kit, $items] = woSeedKit();
    $qc = Inventory::locations()->create(new CreateLocation('qc-bay', 'QC Inspection Bay'));

    $command = new WriteOffStock(
        reference: 'return:rma-2:write_off',
        channel: $channel,
        lines: [new WriteOffLine($kit, 1)],
        locationCode: 'qc-bay',
        reason: 'defective',
    );

    app(StockReturns::class)->writeOff($command);
    app(StockReturns::class)->writeOff($command);

    expect(Inventory::stock()->level($items['carafe'], $qc->id)->onHand)->toBe(0)
        ->and(Inventory::stock()->level($items['filter'], $qc->id)->onHand)->toBe(0)
        ->and(Inventory::movements()->list(new ListMovements(reference: 'return:rma-2:write_off'))->items)->toHaveCount(2)
        ->and(fn () => app(StockReturns::class)->writeOff(new WriteOffStock(
            reference: 'return:rma-2:write_off',
            channel: $channel,
            lines: [new WriteOffLine($kit, 2)],
            locationCode: 'qc-bay',
            reason: 'defective',
        )))->toThrow(IdempotencyConflict::class);
});

it('contrasts Restock (+on_hand) vs DamagedWriteOff (unchanged on_hand) and validates WriteOffStock commands', function (): void {
    [$channel, $kit, $items] = woSeedKit();
    $wh = Inventory::locations()->findByCode('wh-main')->id;

    app(StockReturns::class)->restock(new RestockStock('return:rma-restock', $channel, [new RestockLine($kit, 1)], 'wh-main'));
    expect(Inventory::stock()->level($items['carafe'], $wh)->onHand)->toBe(8);

    app(StockReturns::class)->writeOff(new WriteOffStock('return:rma-writeoff', $channel, [new WriteOffLine($kit, 2)], 'wh-main', 'defective'));
    expect(Inventory::stock()->level($items['carafe'], $wh)->onHand)->toBe(8);

    expect(fn () => new WriteOffLine($kit, 0))->toThrow(InvalidCommand::class)
        ->and(fn () => new WriteOffStock('', $channel, [new WriteOffLine($kit, 1)]))->toThrow(InvalidCommand::class)
        ->and(fn () => new WriteOffStock('return:x', $channel, []))->toThrow(InvalidCommand::class)
        ->and(fn () => new WriteOffStock('return:x', $channel, [new WriteOffLine($kit, 1)], reason: ''))->toThrow(InvalidCommand::class);
});
