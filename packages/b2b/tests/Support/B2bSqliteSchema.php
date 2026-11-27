<?php

declare(strict_types=1);

namespace Commerz\B2b\Tests\Support;

use Commerz\Errors\IdempotencyConflict;
use Commerz\Identity\IdempotencyKey;
use Commerz\Operations\Contracts\OperationReceipts;
use Commerz\Operations\Contracts\Transactions;
use Commerz\Schema\CommerzMigration;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class B2bSqliteSchema
{
    public static function boot(string $connectionName, bool $withSales = false): void
    {
        config()->set("database.connections.{$connectionName}", [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        config()->set('commerz.connection', $connectionName);
        DB::purge($connectionName);

        $db = DB::connection($connectionName);
        $db->statement('PRAGMA foreign_keys = ON');

        app()->singleton(Transactions::class, static fn (): Transactions => new readonly class($db) implements Transactions
        {
            public function __construct(private ConnectionInterface $db) {}

            public function run(callable $work, int $lockTimeoutMs = 3000): mixed
            {
                return $this->db->transaction(static fn (): mixed => $work());
            }

            public function inTransaction(): bool
            {
                return $this->db->transactionLevel() > 0;
            }
        });

        app()->singleton(OperationReceipts::class, static fn (): OperationReceipts => new readonly class($db) implements OperationReceipts
        {
            public function __construct(private ConnectionInterface $db) {}

            public function once(string $scope, IdempotencyKey $key, string $fingerprint, callable $work, callable $encode, callable $decode): mixed
            {
                $existing = $this->db->table('cmz_operation_receipts')
                    ->where('scope', $scope)
                    ->where('key', (string) $key)
                    ->first();

                if ($existing !== null) {
                    if ($existing->fingerprint !== $fingerprint) {
                        throw new IdempotencyConflict($scope, (string) $key);
                    }

                    $stored = $existing->result;
                    if (! is_string($stored)) {
                        throw new RuntimeException('Stored receipt result must be a string.');
                    }

                    $decoded = json_decode($stored, true, flags: JSON_THROW_ON_ERROR);
                    if (! is_array($decoded)) {
                        throw new RuntimeException('Stored receipt result must be a JSON array.');
                    }

                    return $decode($decoded);
                }

                $result = $work();
                $this->db->table('cmz_operation_receipts')->insert([
                    'scope' => $scope,
                    'key' => (string) $key,
                    'fingerprint' => $fingerprint,
                    'result' => json_encode($encode($result), JSON_THROW_ON_ERROR),
                ]);

                return $result;
            }
        });

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
                version integer not null,
                aggregate_ref text not null,
                payload text not null,
                occurred_at text not null,
                available_at text not null default current_timestamp,
                attempts integer not null default 0,
                delivered_at text,
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
                created_at text not null default current_timestamp,
                updated_at text not null default current_timestamp
            )
        SQL);

        $db->statement(<<<'SQL'
            create table cmz_channels (
                id text primary key,
                store_id text not null references cmz_stores(id),
                code text not null unique,
                name text not null,
                currencies text not null,
                default_currency text not null,
                locales text not null,
                default_locale text not null,
                prices_include_tax integer not null default 0,
                status text not null default 'active',
                created_at text not null default current_timestamp,
                updated_at text not null default current_timestamp
            )
        SQL);

        if ($withSales) {
            $db->statement(<<<'SQL'
                create table cmz_carts (
                    id text primary key,
                    kind text not null default 'cart',
                    state text not null default 'open',
                    channel_id text not null,
                    currency text not null,
                    locale text not null,
                    customer_ref text,
                    customer_groups text not null default '[]',
                    codes text not null default '[]',
                    shipping_address text,
                    billing_address text,
                    delivery_method text,
                    created_by_ref text,
                    version integer not null default 1,
                    converted_at text,
                    created_at text not null default current_timestamp,
                    updated_at text not null default current_timestamp
                )
            SQL);

            $db->statement(<<<'SQL'
                create table cmz_cart_lines (
                    id text primary key,
                    cart_id text not null references cmz_carts(id) on delete cascade,
                    sellable_ref text not null,
                    seller_ref text,
                    quantity integer not null,
                    position integer not null,
                    created_at text not null default current_timestamp,
                    updated_at text not null default current_timestamp,
                    unique (cart_id, sellable_ref)
                )
            SQL);

            $db->statement(<<<'SQL'
                create table cmz_orders (
                    id text primary key,
                    number text not null,
                    store_id text not null,
                    channel_id text not null,
                    cart_id text not null unique references cmz_carts(id),
                    origin text not null,
                    state text not null,
                    payment_state text not null,
                    fulfillment_state text not null,
                    cancel_reason text,
                    currency text not null,
                    locale text not null,
                    customer_ref text,
                    customer_groups text not null,
                    customer_email text,
                    customer_name text,
                    codes text not null,
                    shipping_address text,
                    billing_address text,
                    delivery text,
                    order_adjustments text not null,
                    delivery_adjustments text not null,
                    delivery_tax_lines text not null,
                    subtotal_minor integer not null,
                    discount_total_minor integer not null,
                    delivery_total_minor integer not null,
                    tax_total_minor integer not null,
                    total_minor integer not null,
                    amount_due_minor integer not null,
                    authorized_minor integer not null default 0,
                    captured_minor integer not null default 0,
                    refunded_minor integer not null default 0,
                    quote_fingerprint text not null,
                    payment_due_at text,
                    placed_at text not null,
                    completed_at text,
                    cancelled_at text,
                    created_by_ref text,
                    created_at text not null default current_timestamp,
                    updated_at text not null default current_timestamp,
                    unique (store_id, number)
                )
            SQL);

            $db->statement(<<<'SQL'
                create table cmz_order_lines (
                    id text primary key,
                    order_id text not null references cmz_orders(id) on delete cascade,
                    position integer not null,
                    sellable_ref text not null,
                    seller_ref text,
                    sku text not null,
                    name text not null,
                    kind text not null,
                    requires_shipping integer not null,
                    options text not null,
                    tax_class text,
                    weight_grams integer,
                    quantity integer not null,
                    currency text not null,
                    unit_price_minor integer not null,
                    compare_at_minor integer,
                    tax_included integer not null,
                    price_source text not null,
                    subtotal_minor integer not null,
                    total_minor integer not null,
                    adjustments text not null,
                    tax_lines text not null,
                    cancelled_quantity integer not null default 0,
                    fulfilled_quantity integer not null default 0,
                    returned_quantity integer not null default 0
                )
            SQL);

            $db->statement(<<<'SQL'
                create table cmz_order_events (
                    id integer primary key autoincrement,
                    order_id text not null references cmz_orders(id) on delete cascade,
                    type text not null,
                    actor_ref text,
                    data text not null default '{}',
                    created_at text not null default current_timestamp
                )
            SQL);
        }

        $migration = include dirname(__DIR__, 2).'/database/migrations/2026_11_26_600002_create_commerz_b2b_tables.php';
        if (! $migration instanceof CommerzMigration || ! method_exists($migration, 'up')) {
            throw new RuntimeException('B2B migration must be a CommerzMigration.');
        }

        $migration->up();
    }
}
