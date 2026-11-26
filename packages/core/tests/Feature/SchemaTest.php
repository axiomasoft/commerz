<?php

declare(strict_types=1);

use Commerz\Configuration\CommerzOptions;
use Commerz\Plugins\CorePlugin;
use Commerz\Schema\CommerzMigration;
use Commerz\Schema\TableNames;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Packman\Config\Exceptions\InvalidConfiguration;
use Packman\Schema\Contracts\Compiler\SchemaOwnership;
use Packman\Schema\Data\Compiler\ConstraintNames;
use Packman\Schema\Data\Compiler\SchemaFingerprint;
use Packman\Schema\Data\Compiler\TableNames as PackmanTables;
use Packman\Schema\Laravel\PackmanMigration;
use Packman\Schema\Laravel\SchemaExtensions;

uses(RefreshDatabase::class);

/** @return list<string> */
function constraintsOf(string $table): array
{
    return array_map(
        static fn (object $row): string => $row->conname,
        DB::select('select conname from pg_constraint where conrelid = ?::regclass order by conname', [$table]),
    );
}

/** @return list<string> */
function indexesOf(string $table): array
{
    return array_map(
        static fn (object $row): string => $row->indexname,
        DB::select('select indexname from pg_indexes where tablename = ? order by indexname', [$table]),
    );
}

function usePrefix(string $prefix): void
{
    config()->set('commerz.table_prefix', $prefix);
    app()->forgetInstance(CommerzOptions::class);
    app()->forgetInstance(TableNames::class);
}

it('names constraints by the canon and shortens long names deterministically', function (): void {
    expect(ConstraintNames::index('cmz_stock_levels', ['stock_item_id', 'location_id']))->toBe('cmz_stock_levels_stock_item_id_location_id_ix')
        ->and(ConstraintNames::unique('cmz_orders', 'cart_id'))->toBe('cmz_orders_cart_id_uq')
        ->and(ConstraintNames::foreign('cmz_channels', 'store_id'))->toBe('cmz_channels_store_id_fk')
        ->and(ConstraintNames::check('cmz_stock_levels', 'reserved_not_negative'))->toBe('cmz_stock_levels_reserved_not_negative_ck')
        ->and(ConstraintNames::exclusion('cmz_prices', 'validity_overlap'))->toBe('cmz_prices_validity_overlap_ex')
        ->and(ConstraintNames::primary('cmz_orders'))->toBe('cmz_orders_pk');

    $long = 'cmz_tax_rates_'.str_repeat('x', 50).'_ex';
    $exclusion = ConstraintNames::exclusion('cmz_tax_rates', str_repeat('x', 50));

    expect($exclusion)->toHaveLength(63)
        ->toBe(substr($long, 0, 54).'_'.substr(sha1($long), 0, 8));

    $columns = ['promotion_id', 'customer_ref', 'coupon_id', 'order_ref'];
    $full = 'cmz_promotion_customer_usages_promotion_id_customer_ref_coupon_id_order_ref_uq';
    $short = ConstraintNames::unique('cmz_promotion_customer_usages', $columns);

    expect($short)->toHaveLength(63)
        ->toBe(substr($full, 0, 54).'_'.substr(sha1($full), 0, 8))
        ->and(ConstraintNames::unique('cmz_promotion_customer_usages', $columns))->toBe($short);

    expect(fn () => ConstraintNames::index('cmz_x', []))->toThrow(InvalidArgumentException::class)
        ->and(fn () => ConstraintNames::index('cmz_x', 'Bad-Column'))->toThrow(InvalidArgumentException::class);
});

it('resolves logical tables of declared nouns only', function (): void {
    $tables = app(TableNames::class);

    expect($tables->table('outbox_messages'))->toBe('cmz_outbox_messages')
        ->and($tables->table('stores'))->toBe('cmz_stores')
        ->and($tables->table('schema_state'))->toBe('cmz_schema_state')
        ->and($tables->prefix())->toBe('cmz_')
        ->and($tables->connection())->toBeNull();

    expect(fn () => $tables->table('stock_levels'))->toThrow(LogicException::class, 'has no owner')
        ->and(fn () => $tables->table('Stock-Levels'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $tables->table('outbox_'.str_repeat('x', 60)))->toThrow(InvalidArgumentException::class, '63 bytes');
});

it('records noun ownership and refuses a noun claimed twice', function (): void {
    $ownership = SchemaExtensions::ownershipFor(app(SchemaOwnership::class), (new CorePlugin)->tablePrefix());
    $ownership->declare('commerz.inventory', ['stock']);
    $ownership->declare('acme.loyalty', ['x_acme']);

    expect($ownership->owners()['commerz.core'])->toBe(['store', 'channel', 'schema', 'operation', 'outbox', 'custom_field'])
        ->and($ownership->ownerOf('custom_field_index'))->toBe('commerz.core')
        ->and($ownership->ownerOf('stock_levels'))->toBe('commerz.inventory')
        ->and($ownership->ownerOf('stores'))->toBe('commerz.core')
        ->and($ownership->ownerOf('operation_receipts'))->toBe('commerz.core')
        ->and($ownership->ownerOf('x_acme_points'))->toBe('acme.loyalty')
        ->and($ownership->ownerOf('stockpile'))->toBeNull()
        ->and($ownership->ownerOf('orders'))->toBeNull();

    expect(fn () => $ownership->declare('acme.other', ['stock']))
        ->toThrow(InvalidConfiguration::class, "noun 'stock' is already owned by 'commerz.inventory'");
});

it('creates the core tables with canonical constraint names and the install fingerprint', function (): void {
    expect(constraintsOf('cmz_schema_state'))->toBe(['cmz_schema_state_pk'])
        ->and(constraintsOf('cmz_operation_receipts'))->toBe(['cmz_operation_receipts_pk'])
        ->and(indexesOf('cmz_operation_receipts'))->toBe(['cmz_operation_receipts_created_at_ix', 'cmz_operation_receipts_pk'])
        ->and(constraintsOf('cmz_outbox_messages'))->toBe([
            'cmz_outbox_messages_attempts_not_negative_ck',
            'cmz_outbox_messages_event_id_uq',
            'cmz_outbox_messages_pk',
            'cmz_outbox_messages_version_positive_ck',
        ])
        ->and(indexesOf('cmz_outbox_messages'))->toContain('cmz_outbox_messages_available_at_id_ix', 'cmz_outbox_messages_delivered_at_ix');

    $identity = DB::selectOne("select is_identity, identity_generation from information_schema.columns where table_name = 'cmz_outbox_messages' and column_name = 'id'");
    expect($identity->is_identity)->toBe('YES')->and($identity->identity_generation)->toBe('ALWAYS');

    $stored = json_decode(DB::table('cmz_schema_state')->where('key', 'fingerprint')->value('value'), true);
    expect($stored)->toEqual(SchemaFingerprint::document('cmz_', TableNames::OWNER))
        ->and($stored['id_strategy'])->toBe('uuid7')
        ->and($stored['schema_version'])->toBe(1)
        ->and($stored['hash'])->toMatch('/\A[0-9a-f]{64}\z/');
});

it('refuses a prefix that differs from the installed one', function (): void {
    usePrefix('shp_');

    expect(fn () => app(TableNames::class)->table('operation_receipts'))
        ->toThrow(function (InvalidConfiguration $e): void {
            expect($e->getMessage())->toContain('commerz.table_prefix')
                ->and($e->getMessage())->toContain("installed with prefix 'cmz_'");
        });
});

it('ignores host tables that only look like a state table', function (): void {
    DB::statement('create table abc_schema_state (key text primary key, value text)');
    DB::table('abc_schema_state')->insert(['key' => 'fingerprint', 'value' => '{"prefix":"zzz_"}']);
    DB::statement('alter table cmz_schema_state rename to old_schema_state');

    usePrefix('new_');

    // Renamed away, so `old_` no longer matches its stored prefix `cmz_`; the host table has no id strategy.
    expect(app(TableNames::class)->table('outbox_messages'))->toBe('new_outbox_messages');
});

it('installs Commerz and an independent Packman schema in either order', function (bool $commerzFirst): void {
    DB::statement('create schema coexist_commerz');
    DB::statement('set local search_path to coexist_commerz');
    $prefix = $commerzFirst ? 'cmz_' : 'trade_';
    usePrefix($prefix);
    app()->forgetInstance(PackmanTables::class);

    app(SchemaOwnership::class)->declare('example.host', ['schema']);
    $host = new class extends PackmanMigration
    {
        public function up(): void
        {
            $name = $this->create('schema_state', static function (Blueprint $table): void {
                $table->string('key')->primary();
                $table->jsonb('value');
            });

            $this->db()->table($name)->insert([
                'key' => SchemaFingerprint::KEY,
                'value' => json_encode(SchemaFingerprint::document($this->tables()->prefix()), JSON_THROW_ON_ERROR),
            ]);
        }
    };
    $commerz = static function (): void {
        foreach (glob(dirname(__DIR__, 2).'/database/migrations/*.php') ?: [] as $path) {
            (require $path)->up();
        }
    };

    if ($commerzFirst) {
        $commerz();
        $host->up();
    } else {
        $host->up();
        $commerz();
    }

    expect(app(PackmanTables::class)->table('schema_state'))->toBe('pmn_schema_state')
        ->and(app(TableNames::class)->table('schema_state'))->toBe($prefix.'schema_state')
        ->and(DB::table('pmn_schema_state')->count())->toBe(1)
        ->and(DB::table($prefix.'schema_state')->count())->toBe(1)
        ->and(DB::connection()->getSchemaBuilder()->hasTable('pmn_operation_receipts'))->toBeFalse();
})->with([true, false]);

it('identifies legacy commerce schemas on upgrade and preserves unrelated fingerprint fields', function (): void {
    $legacy = SchemaFingerprint::document('cmz_') + ['host_key' => 'keep'];
    $row = DB::table('cmz_schema_state')->where('key', SchemaFingerprint::KEY);
    $row->update(['value' => json_encode($legacy, JSON_THROW_ON_ERROR)]);

    usePrefix('new_');
    expect(fn () => app(TableNames::class)->table('outbox_messages'))->toThrow(InvalidConfiguration::class, "installed with prefix 'cmz_'");
    usePrefix('cmz_');
    $this->artisan('packman:doctor')->assertSuccessful();

    $migration = require dirname(__DIR__, 2).'/database/migrations/2026_11_19_000001_identify_commerz_schema_owner.php';
    $migration->up();
    $migration->up();
    expect(json_decode($row->value('value'), true))->toEqual(SchemaFingerprint::document('cmz_', TableNames::OWNER) + ['host_key' => 'keep']);
    $migration->down();
    expect(json_decode($row->value('value'), true))->toEqual($legacy);
});

it('preserves a legacy fingerprint with an unsupported schema version for investigation', function (): void {
    $row = DB::table('cmz_schema_state')->where('key', SchemaFingerprint::KEY);
    $stored = array_replace(SchemaFingerprint::document('cmz_'), ['schema_version' => 42]);
    $row->update(['value' => json_encode($stored, JSON_THROW_ON_ERROR)]);

    $migration = require dirname(__DIR__, 2).'/database/migrations/2026_11_19_000001_identify_commerz_schema_owner.php';
    expect(fn () => $migration->up())->toThrow(InvalidConfiguration::class)
        ->and(json_decode($row->value('value'), true))->toEqual($stored);
});

it('gives migrations helpers for keys, money, refs, timestamps and checks', function (): void {
    app(SchemaOwnership::class)->declare('acme.test', ['x_test']);

    $migration = new class extends CommerzMigration
    {
        public function up(): void
        {
            $table = $this->create('x_test_items', function (Blueprint $table): void {
                $this->uuidPrimary($table);
                $this->ref($table, 'sellable');
                $this->ref($table, 'customer', nullable: true);
                $this->money($table, 'price');
                $this->money($table, 'discount');
                $table->integer('quantity');
                $this->unique($table, ['sellable_ref', 'customer_ref']);
                $this->timestampsTz($table);
            });

            $this->check($table, 'quantity_positive', 'quantity > 0');
            $this->db()->statement('create extension if not exists btree_gist');
            $this->exclude($table, 'sellable_once', 'sellable_ref with =');
        }

        public function down(): void
        {
            $this->drop('x_test_items');
        }
    };

    $migration->up();

    $columns = collect(DB::select("select column_name, data_type, character_maximum_length, is_nullable from information_schema.columns where table_name = 'cmz_x_test_items'"))
        ->keyBy('column_name');

    expect($columns->keys()->sort()->values()->all())->toBe([
        'created_at', 'currency', 'customer_ref', 'discount_minor', 'id', 'price_minor', 'quantity', 'sellable_ref', 'updated_at',
    ])
        ->and($columns['id']->data_type)->toBe('uuid')
        ->and($columns['price_minor']->data_type)->toBe('bigint')
        ->and($columns['currency']->data_type)->toBe('character')
        ->and($columns['currency']->character_maximum_length)->toBe(3)
        ->and($columns['sellable_ref']->character_maximum_length)->toBe(191)
        ->and($columns['customer_ref']->is_nullable)->toBe('YES')
        ->and($columns['created_at']->data_type)->toBe('timestamp with time zone')
        ->and($columns['updated_at']->is_nullable)->toBe('NO')
        ->and(constraintsOf('cmz_x_test_items'))->toBe([
            'cmz_x_test_items_pk',
            'cmz_x_test_items_quantity_positive_ck',
            'cmz_x_test_items_sellable_once_ex',
            'cmz_x_test_items_sellable_ref_customer_ref_uq',
        ])
        ->and($migration->getConnection())->toBeNull();

    $migration->down();

    expect(DB::selectOne("select to_regclass('cmz_x_test_items') as t")->t)->toBeNull();
});
