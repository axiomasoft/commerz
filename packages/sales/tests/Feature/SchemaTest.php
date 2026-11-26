<?php

declare(strict_types=1);

use Commerz\Identity\Uuid7;
use Commerz\Sales\SalesServiceProvider;
use Commerz\Schema\TableNames;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Packman\Schema\Contracts\Compiler\SchemaOwnership;
use Packman\Schema\Data\Compiler\ConstraintNames;

uses(RefreshDatabase::class);

const SALES_TABLES = [
    'carts', 'cart_lines', 'orders', 'order_lines', 'order_events',
    'order_changes', 'order_change_actions',
    'fulfillments', 'fulfillment_lines', 'returns', 'return_lines',
    'payments', 'refunds', 'payment_events',
    'payment_collections', 'payment_sessions', 'payment_captures',
];

const SALES_NOW = '2026-10-14 10:00:00+00';

it('adds booking intent to existing fulfillments without rewriting their shipment on upgrade or rollback', function (): void {
    $id = Uuid7::generate();
    DB::table(slsTable('fulfillments'))->insert([
        'id' => $id, 'order_id' => slsOrder(), 'status' => 'shipped', 'carrier' => 'manual',
        'tracking' => 'OLD-1', 'shipped_at' => SALES_NOW,
    ]);
    $migration = require __DIR__.'/../../database/migrations/2026_10_14_100005_add_commerz_fulfillment_booking_intent.php';
    $migration->down();
    expect(DB::getSchemaBuilder()->hasColumn(slsTable('fulfillments'), 'booking_requested'))->toBeFalse()
        ->and(DB::table(slsTable('fulfillments'))->where('id', $id)->value('tracking'))->toBe('OLD-1');
    $migration->up();
    $stored = DB::table(slsTable('fulfillments'))->where('id', $id)->first();

    expect([$stored->booking_requested, $stored->tracking, $stored->carrier])->toBe([false, 'OLD-1', 'manual']);
});

function slsTable(string $logical): string
{
    return app(TableNames::class)->table($logical);
}

/** @return list<string> */
function slsConstraints(string $logical): array
{
    return array_map(
        static fn (object $row): string => $row->conname,
        DB::select('select conname from pg_constraint where conrelid = ?::regclass order by conname', [slsTable($logical)]),
    );
}

// RefreshDatabase wraps each test in a transaction; DB::transaction opens a savepoint that keeps it usable after an expected violation.

/** @param  array<string, mixed>  $row */
function slsInsert(string $logical, array $row): void
{
    DB::transaction(static fn () => DB::table(slsTable($logical))->insert($row));
}

/** @param  array<string, mixed>  $set */
function slsUpdate(string $logical, string $key, string $id, array $set): void
{
    DB::transaction(static fn () => DB::table(slsTable($logical))->where($key, $id)->update($set));
}

/** The SQLSTATE a statement fails with, or null when it succeeds. */
function slsSqlState(Closure $statement): ?string
{
    try {
        $statement();
    } catch (QueryException $e) {
        return $e->errorInfo[0] ?? null;
    }

    return null;
}

const SLS_CHECK = '23514';
const SLS_UNIQUE = '23505';
const SLS_FOREIGN = '23503';

/** @param  array<string, mixed>  $extra */
function slsCart(array $extra = []): string
{
    $id = Uuid7::generate();

    slsInsert('carts', ['id' => $id, 'channel_id' => Uuid7::generate(), 'currency' => 'RUB', 'locale' => 'ru-RU', ...$extra]);

    return $id;
}

/** @param  array<string, mixed>  $extra */
function slsOrder(?string $cart = null, array $extra = []): string
{
    $id = Uuid7::generate();

    slsInsert('orders', [
        'id' => $id, 'number' => 'ORD-'.random_int(1000, 999999), 'store_id' => Uuid7::generate(), 'channel_id' => Uuid7::generate(),
        'cart_id' => $cart ?? slsCart(), 'origin' => 'cart', 'state' => 'placed', 'payment_state' => 'unpaid', 'fulfillment_state' => 'unfulfilled',
        'currency' => 'RUB', 'locale' => 'ru-RU', 'customer_groups' => '[]', 'codes' => '[]',
        'order_adjustments' => '[]', 'delivery_adjustments' => '[]', 'delivery_tax_lines' => '[]',
        'subtotal_minor' => 1000, 'discount_total_minor' => 0, 'delivery_total_minor' => 0, 'tax_total_minor' => 0, 'total_minor' => 1000, 'amount_due_minor' => 1000,
        'quote_fingerprint' => str_repeat('a', 64), 'placed_at' => SALES_NOW,
        ...$extra,
    ]);

    return $id;
}

/** @param  array<string, mixed>  $extra */
function slsOrderLine(string $order, array $extra = []): string
{
    $id = Uuid7::generate();

    slsInsert('order_lines', [
        'id' => $id, 'order_id' => $order, 'position' => 0, 'sellable_ref' => 'variant:'.$id, 'sku' => 'SKU-1', 'name' => 'Thing', 'kind' => 'physical',
        'requires_shipping' => true, 'options' => '{}', 'quantity' => 5, 'currency' => 'RUB', 'unit_price_minor' => 200, 'tax_included' => false,
        'price_source' => 'snapshot', 'subtotal_minor' => 1000, 'total_minor' => 1000, 'adjustments' => '[]', 'tax_lines' => '[]',
        ...$extra,
    ]);

    return $id;
}

/** @param  array<string, mixed>  $extra */
function slsPayment(string $order, array $extra = []): string
{
    $id = Uuid7::generate();

    slsInsert('payments', [
        'id' => $id, 'order_id' => $order, 'gateway' => 'manual', 'reference' => 'payment:'.$id, 'status' => 'pending', 'currency' => 'RUB',
        'amount_minor' => 1000, 'fingerprint' => str_repeat('b', 64), 'claimed_at' => SALES_NOW,
        ...$extra,
    ]);

    return $id;
}

/** @param  array<string, mixed>  $extra */
function slsRefund(string $order, string $payment, array $extra = []): string
{
    $id = Uuid7::generate();

    slsInsert('refunds', [
        'id' => $id, 'order_id' => $order, 'payment_id' => $payment, 'gateway' => 'manual', 'reference' => 'refund:'.$id, 'status' => 'pending',
        'currency' => 'RUB', 'amount_minor' => 100, 'fingerprint' => str_repeat('c', 64), 'claimed_at' => SALES_NOW,
        ...$extra,
    ]);

    return $id;
}

/** @param  array<string, mixed>  $extra */
function slsPaymentEvent(array $extra): void
{
    slsInsert('payment_events', [
        'gateway' => 'manual', 'external_event_id' => 'evt-'.random_int(1, 1_000_000), 'type' => 'captured', 'fingerprint' => str_repeat('d', 64), 'outcome' => 'applied',
        ...$extra,
    ]);
}

it('declares the sales nouns under commerz.sales', function (): void {
    $ownership = app(SchemaOwnership::class);

    expect($ownership->owners()['commerz.sales'])->toBe(SalesServiceProvider::NOUNS);

    foreach ([...SALES_TABLES, 'order_number_seq'] as $logical) {
        expect($ownership->ownerOf($logical))->toBe('commerz.sales', $logical);
    }
});

it('creates every table with canonical constraint names', function (): void {
    foreach (SALES_TABLES as $logical) {
        $physical = slsTable($logical);

        expect(DB::getSchemaBuilder()->hasTable($physical))->toBeTrue("table {$physical}");

        foreach (slsConstraints($logical) as $name) {
            expect($name)->toMatch('/(_(pk|uq|fk|ck|ex)|_[0-9a-f]{8})\z/');
        }

        foreach (DB::select('select indexname from pg_indexes where tablename = ?', [$physical]) as $index) {
            expect($index->indexname)->toMatch('/(_(pk|uq|ix|ex)|_[0-9a-f]{8})\z/');
        }

        expect(slsConstraints($logical))->toContain(ConstraintNames::primary($physical));
    }
});

it('keeps money in bigint, a currency on every table that has an amount, and ids as uuid', function (): void {
    $column = static fn (string $logical, string $name): object => DB::selectOne(
        'select data_type, is_nullable from information_schema.columns where table_name = ? and column_name = ?',
        [slsTable($logical), $name],
    );

    foreach (['orders' => 'total_minor', 'order_lines' => 'unit_price_minor', 'payments' => 'amount_minor', 'refunds' => 'amount_minor'] as $logical => $amount) {
        expect($column($logical, $amount)->data_type)->toBe('bigint')
            ->and($column($logical, 'currency')->data_type)->toBe('character')
            ->and($column($logical, 'currency')->is_nullable)->toBe('NO');
    }

    expect($column('carts', 'currency')->is_nullable)->toBe('NO')
        ->and($column('payment_events', 'currency')->is_nullable)->toBe('YES')
        ->and($column('payment_events', 'amount_minor')->is_nullable)->toBe('YES')
        ->and($column('orders', 'channel_id')->data_type)->toBe('uuid')
        ->and($column('orders', 'store_id')->data_type)->toBe('uuid')
        ->and($column('order_lines', 'id')->data_type)->toBe('uuid')
        ->and($column('order_events', 'id')->data_type)->toBe('bigint')
        ->and($column('payment_events', 'id')->data_type)->toBe('bigint')
        ->and($column('orders', 'delivery')->data_type)->toBe('jsonb')
        ->and($column('payments', 'claimed_at')->data_type)->toBe('timestamp with time zone')
        ->and($column('payments', 'claimed_at')->is_nullable)->toBe('NO');
});

it('has no foreign key to the core or other packages', function (): void {
    foreach (SALES_TABLES as $logical) {
        $targets = DB::select(
            "select confrelid::regclass::text as target from pg_constraint where conrelid = ?::regclass and contype = 'f'",
            [slsTable($logical)],
        );

        foreach ($targets as $row) {
            $owned = array_map(slsTable(...), SALES_TABLES);

            expect(in_array($row->target, $owned, true))->toBeTrue("{$logical} references {$row->target}");
        }
    }
});

it('checks the cart kind, state, version, currency and the conversion time', function (): void {
    $cart = slsCart();
    $update = static fn (array $set): Closure => static fn () => slsUpdate('carts', 'id', $cart, $set);

    expect(slsSqlState(static fn () => slsCart(['kind' => 'wishlist'])))->toBe(SLS_CHECK)
        ->and(slsSqlState(static fn () => slsCart(['state' => 'closed'])))->toBe(SLS_CHECK)
        ->and(slsSqlState(static fn () => slsCart(['version' => 0])))->toBe(SLS_CHECK)
        ->and(slsSqlState(static fn () => slsCart(['currency' => 'rub'])))->toBe(SLS_CHECK)
        ->and(slsSqlState(static fn () => slsCart(['currency' => null])))->not->toBeNull()
        ->and(slsSqlState(static fn () => slsCart(['codes' => '{}'])))->toBe(SLS_CHECK)
        ->and(slsSqlState(static fn () => slsCart(['customer_groups' => '{}'])))->toBe(SLS_CHECK)
        ->and(slsSqlState($update(['state' => 'converted'])))->toBe(SLS_CHECK)
        ->and(slsSqlState($update(['converted_at' => SALES_NOW])))->toBe(SLS_CHECK)
        ->and(slsSqlState($update(['state' => 'converted', 'converted_at' => SALES_NOW])))->toBeNull()
        ->and(slsSqlState(static fn () => slsCart(['kind' => 'draft'])))->toBeNull();

    $row = DB::table(slsTable('carts'))->where('id', slsCart())->first();

    expect($row->kind)->toBe('cart')->and($row->state)->toBe('open')->and($row->version)->toBe(1)->and($row->codes)->toBe('[]');
});

it('keeps one line per sellable in a cart and a positive quantity', function (): void {
    $cart = slsCart();
    $line = static fn (string $cart, array $extra = []): Closure => static fn () => slsInsert('cart_lines', [
        'id' => Uuid7::generate(), 'cart_id' => $cart, 'sellable_ref' => 'variant:1', 'quantity' => 1, 'position' => 0, ...$extra,
    ]);

    expect(slsSqlState($line($cart)))->toBeNull()
        ->and(slsSqlState($line($cart)))->toBe(SLS_UNIQUE)
        ->and(slsSqlState($line($cart, ['sellable_ref' => 'variant:2'])))->toBeNull()
        ->and(slsSqlState($line(slsCart())))->toBeNull() // another cart may hold the same sellable
        ->and(slsSqlState($line($cart, ['sellable_ref' => 'variant:3', 'quantity' => 0])))->toBe(SLS_CHECK)
        ->and(slsSqlState($line(Uuid7::generate(), ['sellable_ref' => 'variant:4'])))->toBe(SLS_FOREIGN);
});

it('places at most one order per cart (ORD-01) and keeps the number unique per store', function (): void {
    $cart = slsCart();
    $store = Uuid7::generate();
    slsOrder($cart, ['store_id' => $store, 'number' => 'ORD-1001']);

    expect(fn () => slsOrder($cart))->toThrow(UniqueConstraintViolationException::class, ConstraintNames::unique(slsTable('orders'), 'cart_id'))
        ->and(fn () => slsOrder(null, ['store_id' => $store, 'number' => 'ORD-1001']))->toThrow(UniqueConstraintViolationException::class, ConstraintNames::unique(slsTable('orders'), ['store_id', 'number']))
        ->and(slsSqlState(static fn () => slsOrder(null, ['number' => 'ORD-1001'])))->toBeNull() // another store numbers on its own
        ->and(slsSqlState(static fn () => slsOrder(Uuid7::generate())))->toBe(SLS_FOREIGN);
});

it('checks the three states, the cancel reason and their timestamps of an order', function (): void {
    $order = slsOrder();
    $update = static fn (array $set): Closure => static fn () => slsUpdate('orders', 'id', $order, $set);

    expect(slsSqlState(static fn () => slsOrder(null, ['state' => 'draft'])))->toBe(SLS_CHECK)
        ->and(slsSqlState(static fn () => slsOrder(null, ['origin' => 'crm'])))->toBe(SLS_CHECK)
        ->and(slsSqlState(static fn () => slsOrder(null, ['payment_state' => 'half_paid'])))->toBe(SLS_CHECK)
        ->and(slsSqlState(static fn () => slsOrder(null, ['fulfillment_state' => 'shipped'])))->toBe(SLS_CHECK)
        ->and(slsSqlState(static fn () => slsOrder(null, ['cancel_reason' => 'boredom'])))->toBe(SLS_CHECK)
        ->and(slsSqlState($update(['state' => 'cancelled'])))->toBe(SLS_CHECK)
        ->and(slsSqlState($update(['state' => 'cancelled', 'cancelled_at' => SALES_NOW])))->toBe(SLS_CHECK)
        ->and(slsSqlState($update(['cancelled_at' => SALES_NOW, 'cancel_reason' => 'customer'])))->toBe(SLS_CHECK)
        ->and(slsSqlState($update(['state' => 'cancelled', 'cancelled_at' => SALES_NOW, 'cancel_reason' => 'expired'])))->toBeNull()
        ->and(slsSqlState($update(['state' => 'completed'])))->toBe(SLS_CHECK)
        ->and(slsSqlState($update(['state' => 'completed', 'completed_at' => SALES_NOW, 'cancelled_at' => null, 'cancel_reason' => null])))->toBeNull()
        ->and(slsSqlState($update(['payment_state' => 'partially_refunded', 'fulfillment_state' => 'partially_returned'])))->toBeNull();
});

it('checks the amounts and the number of an order', function (): void {
    expect(slsSqlState(static fn () => slsOrder(null, ['total_minor' => -1, 'amount_due_minor' => 0])))->toBe(SLS_CHECK)
        ->and(slsSqlState(static fn () => slsOrder(null, ['amount_due_minor' => 1001])))->toBe(SLS_CHECK)
        ->and(slsSqlState(static fn () => slsOrder(null, ['amount_due_minor' => -1])))->toBe(SLS_CHECK)
        ->and(slsSqlState(static fn () => slsOrder(null, ['amount_due_minor' => 0])))->toBeNull()
        ->and(slsSqlState(static fn () => slsOrder(null, ['authorized_minor' => -1])))->toBe(SLS_CHECK)
        ->and(slsSqlState(static fn () => slsOrder(null, ['captured_minor' => -1])))->toBe(SLS_CHECK)
        ->and(slsSqlState(static fn () => slsOrder(null, ['captured_minor' => 100, 'refunded_minor' => 101])))->toBe(SLS_CHECK) // refunded > captured
        ->and(slsSqlState(static fn () => slsOrder(null, ['captured_minor' => 100, 'refunded_minor' => 100])))->toBeNull()
        ->and(slsSqlState(static fn () => slsOrder(null, ['currency' => 'RU'])))->not->toBeNull()
        ->and(slsSqlState(static fn () => slsOrder(null, ['currency' => 'rub'])))->toBe(SLS_CHECK);

    foreach (['-', '---', 'a b', 'ORD_1', '', str_repeat('A', 65)] as $number) {
        expect(slsSqlState(static fn () => slsOrder(null, ['number' => $number])))->not->toBeNull("number '{$number}'");
    }

    foreach (['1001', 'ORD-1001', '-1001', 'a', str_repeat('A', 64)] as $number) {
        expect(slsSqlState(static fn () => slsOrder(null, ['number' => $number])))->toBeNull("number '{$number}'");
    }
});

it('keeps the line journal inside the quantity (ORD-03)', function (): void {
    $order = slsOrder();
    $line = slsOrderLine($order, ['quantity' => 5]);
    $update = static fn (array $set): Closure => static fn () => slsUpdate('order_lines', 'id', $line, $set);

    expect(slsSqlState($update(['cancelled_quantity' => 3, 'fulfilled_quantity' => 3])))->toBe(SLS_CHECK) // cancelled + fulfilled > quantity
        ->and(slsSqlState($update(['fulfilled_quantity' => 6])))->toBe(SLS_CHECK)
        ->and(slsSqlState($update(['returned_quantity' => 1])))->toBe(SLS_CHECK) // returned > fulfilled
        ->and(slsSqlState($update(['cancelled_quantity' => -1])))->toBe(SLS_CHECK)
        ->and(slsSqlState($update(['cancelled_quantity' => 2, 'fulfilled_quantity' => 3, 'returned_quantity' => 3])))->toBeNull()
        ->and(slsSqlState($update(['returned_quantity' => 4])))->toBe(SLS_CHECK)
        ->and(slsSqlState(static fn () => slsOrderLine($order, ['quantity' => 0])))->toBe(SLS_CHECK)
        ->and(slsSqlState(static fn () => slsOrderLine($order, ['kind' => 'bundle'])))->toBe(SLS_CHECK)
        ->and(slsSqlState(static fn () => slsOrderLine($order, ['currency' => 'rub'])))->toBe(SLS_CHECK)
        ->and(slsSqlState(static fn () => slsOrderLine(Uuid7::generate())))->toBe(SLS_FOREIGN);
});

it('removes lines and events with their order on a fresh migration but never an order that has a payment', function (): void {
    $order = slsOrder();
    $line = slsOrderLine($order);
    slsInsert('order_events', ['order_id' => $order, 'type' => 'placed']);

    expect(slsSqlState(static fn () => slsInsert('order_events', ['order_id' => $order, 'type' => 'Placed'])))->toBe(SLS_CHECK)
        ->and(slsSqlState(static fn () => slsInsert('order_events', ['order_id' => Uuid7::generate(), 'type' => 'placed'])))->toBe(SLS_FOREIGN);

    slsPayment($order);

    expect(slsSqlState(static fn () => DB::transaction(static fn () => DB::table(slsTable('orders'))->where('id', $order)->delete())))->toBe(SLS_FOREIGN);

    DB::table(slsTable('payments'))->delete();
    DB::table(slsTable('orders'))->where('id', $order)->delete();

    expect(DB::table(slsTable('order_lines'))->where('id', $line)->count())->toBe(0)
        ->and(DB::table(slsTable('order_events'))->where('order_id', $order)->count())->toBe(0);
});

it('checks fulfillments and their lines', function (): void {
    $order = slsOrder();
    $line = slsOrderLine($order);
    $fulfillment = static fn (array $extra = []): Closure => static fn () => slsInsert('fulfillments', ['id' => Uuid7::generate(), 'order_id' => $order, ...$extra]);
    $id = Uuid7::generate();
    slsInsert('fulfillments', ['id' => $id, 'order_id' => $order]);
    $member = static fn (array $extra = []): Closure => static fn () => slsInsert('fulfillment_lines', ['fulfillment_id' => $id, 'order_line_id' => $line, 'quantity' => 1, ...$extra]);

    expect(slsSqlState($fulfillment(['status' => 'lost'])))->toBe(SLS_CHECK)
        ->and(slsSqlState($fulfillment(['status' => 'shipped'])))->toBe(SLS_CHECK)
        ->and(slsSqlState($fulfillment(['status' => 'shipped', 'shipped_at' => SALES_NOW])))->toBeNull()
        ->and(slsSqlState($fulfillment(['status' => 'delivered', 'shipped_at' => SALES_NOW])))->toBe(SLS_CHECK)
        ->and(slsSqlState($fulfillment(['status' => 'delivered', 'shipped_at' => SALES_NOW, 'delivered_at' => SALES_NOW])))->toBeNull()
        ->and(slsSqlState($member(['quantity' => 0])))->toBe(SLS_CHECK)
        ->and(slsSqlState($member()))->toBeNull()
        ->and(slsSqlState($member()))->toBe(SLS_UNIQUE)
        ->and(slsSqlState($member(['order_line_id' => Uuid7::generate()])))->toBe(SLS_FOREIGN);
});

it('checks returns and their lines', function (): void {
    $order = slsOrder();
    $line = slsOrderLine($order);
    $return = static fn (array $extra = []): Closure => static fn () => slsInsert('returns', ['id' => Uuid7::generate(), 'order_id' => $order, ...$extra]);
    $id = Uuid7::generate();
    slsInsert('returns', ['id' => $id, 'order_id' => $order]);
    $member = static fn (array $extra = []): Closure => static fn () => slsInsert('return_lines', ['return_id' => $id, 'order_line_id' => $line, 'quantity' => 2, ...$extra]);

    expect(slsSqlState($return(['status' => 'closed'])))->toBe(SLS_CHECK)
        ->and(slsSqlState($return(['status' => 'received'])))->toBe(SLS_CHECK)
        ->and(slsSqlState($return(['received_at' => SALES_NOW])))->toBe(SLS_CHECK)
        ->and(slsSqlState($return(['status' => 'received', 'received_at' => SALES_NOW])))->toBeNull()
        ->and(slsSqlState($return(['status' => 'cancelled'])))->toBe(SLS_CHECK)
        ->and(slsSqlState($return(['status' => 'cancelled', 'cancelled_at' => SALES_NOW])))->toBeNull()
        ->and(slsSqlState($member(['quantity' => 0])))->toBe(SLS_CHECK)
        ->and(slsSqlState($member(['restocked_quantity' => 3])))->toBe(SLS_CHECK)
        ->and(slsSqlState($member(['restocked_quantity' => -1])))->toBe(SLS_CHECK)
        ->and(slsSqlState($member(['restocked_quantity' => 2])))->toBeNull()
        ->and(slsSqlState($member()))->toBe(SLS_UNIQUE);
});

it('keeps the reference of a payment unique and checks its status and amounts', function (): void {
    $order = slsOrder();
    $id = slsPayment($order, ['reference' => 'payment:one']);
    $update = static fn (array $set): Closure => static fn () => slsUpdate('payments', 'id', $id, $set);

    expect(fn () => slsPayment($order, ['reference' => 'payment:one']))->toThrow(UniqueConstraintViolationException::class, ConstraintNames::unique(slsTable('payments'), 'reference'))
        ->and(slsSqlState(static fn () => slsPayment($order, ['status' => 'paid'])))->toBe(SLS_CHECK)
        ->and(slsSqlState(static fn () => slsPayment($order, ['amount_minor' => 0])))->toBe(SLS_CHECK)
        ->and(slsSqlState(static fn () => slsPayment($order, ['attempts' => 0])))->toBe(SLS_CHECK)
        ->and(slsSqlState(static fn () => slsPayment($order, ['gateway' => 'Manual'])))->toBe(SLS_CHECK)
        ->and(slsSqlState(static fn () => slsPayment($order, ['currency' => 'rub'])))->toBe(SLS_CHECK)
        ->and(slsSqlState(static fn () => slsPayment($order, ['meta' => '[]'])))->toBe(SLS_CHECK)
        ->and(slsSqlState(static fn () => slsPayment($order, ['claimed_at' => null])))->not->toBeNull()
        ->and(slsSqlState(static fn () => slsPayment(Uuid7::generate())))->toBe(SLS_FOREIGN)
        ->and(slsSqlState($update(['authorized_minor' => 1001])))->toBe(SLS_CHECK)
        ->and(slsSqlState($update(['captured_minor' => 1001])))->toBe(SLS_CHECK)
        ->and(slsSqlState($update(['captured_minor' => 500, 'refunded_minor' => 501])))->toBe(SLS_CHECK) // refunded > captured
        ->and(slsSqlState($update(['captured_minor' => 500, 'refunded_minor' => 500, 'status' => 'captured'])))->toBeNull();
});

it('lets two payment attempts of a gateway both have no external id but not share one (PAY-01)', function (): void {
    $order = slsOrder();

    expect(slsSqlState(static fn () => slsPayment($order)))->toBeNull()
        ->and(slsSqlState(static fn () => slsPayment($order)))->toBeNull() // NULL external ids are distinct
        ->and(slsSqlState(static fn () => slsPayment($order, ['external_id' => 'ext-1'])))->toBeNull()
        ->and(slsSqlState(static fn () => slsPayment($order, ['external_id' => 'ext-1'])))->toBe(SLS_UNIQUE)
        ->and(slsSqlState(static fn () => slsPayment($order, ['external_id' => 'ext-1', 'gateway' => 'acme.pay'])))->toBeNull(); // another gateway has its own ids
});

it('keeps refunds the same way and ties them to a payment', function (): void {
    $order = slsOrder();
    $payment = slsPayment($order);

    expect(slsSqlState(static fn () => slsRefund($order, $payment)))->toBeNull()
        ->and(slsSqlState(static fn () => slsRefund($order, $payment)))->toBeNull() // NULL external ids are distinct
        ->and(slsSqlState(static fn () => slsRefund($order, $payment, ['external_id' => 'r-1'])))->toBeNull()
        ->and(slsSqlState(static fn () => slsRefund($order, $payment, ['external_id' => 'r-1'])))->toBe(SLS_UNIQUE)
        ->and(slsSqlState(static fn () => slsRefund($order, $payment, ['reference' => 'refund:same'])))->toBeNull()
        ->and(slsSqlState(static fn () => slsRefund($order, $payment, ['reference' => 'refund:same'])))->toBe(SLS_UNIQUE)
        ->and(slsSqlState(static fn () => slsRefund($order, $payment, ['status' => 'done'])))->toBe(SLS_CHECK)
        ->and(slsSqlState(static fn () => slsRefund($order, $payment, ['amount_minor' => 0])))->toBe(SLS_CHECK)
        ->and(slsSqlState(static fn () => slsRefund($order, $payment, ['attempts' => 0])))->toBe(SLS_CHECK)
        ->and(slsSqlState(static fn () => slsRefund($order, Uuid7::generate())))->toBe(SLS_FOREIGN)
        ->and(slsSqlState(static fn () => slsRefund($order, $payment, ['return_id' => Uuid7::generate()])))->toBe(SLS_FOREIGN);
});

it('records a provider event once per gateway and event id (PAY-01) and ties it to exactly one target', function (): void {
    $order = slsOrder();
    $payment = slsPayment($order);
    $refund = slsRefund($order, $payment);

    expect(slsSqlState(static fn () => slsPaymentEvent(['external_event_id' => 'evt-1', 'payment_id' => $payment])))->toBeNull()
        ->and(slsSqlState(static fn () => slsPaymentEvent(['external_event_id' => 'evt-1', 'payment_id' => $payment])))->toBe(SLS_UNIQUE)
        ->and(slsSqlState(static fn () => slsPaymentEvent(['external_event_id' => 'evt-1', 'gateway' => 'acme.pay', 'payment_id' => $payment])))->toBeNull()
        ->and(slsSqlState(static fn () => slsPaymentEvent(['refund_id' => $refund, 'type' => 'refund_succeeded', 'currency' => 'RUB', 'amount_minor' => 100])))->toBeNull()
        ->and(slsSqlState(static fn () => slsPaymentEvent([])))->toBe(SLS_CHECK) // no target
        ->and(slsSqlState(static fn () => slsPaymentEvent(['payment_id' => $payment, 'refund_id' => $refund])))->toBe(SLS_CHECK) // two targets
        ->and(slsSqlState(static fn () => slsPaymentEvent(['payment_id' => $payment, 'type' => 'refunded'])))->toBe(SLS_CHECK)
        ->and(slsSqlState(static fn () => slsPaymentEvent(['payment_id' => $payment, 'outcome' => 'failed'])))->toBe(SLS_CHECK)
        ->and(slsSqlState(static fn () => slsPaymentEvent(['payment_id' => $payment, 'currency' => 'rub'])))->toBe(SLS_CHECK)
        ->and(slsSqlState(static fn () => slsPaymentEvent(['payment_id' => Uuid7::generate()])))->toBe(SLS_FOREIGN);
});

it('has the order number sequence starting at 1001 that only goes forward', function (): void {
    $sequence = slsTable('order_number_seq');

    $definition = DB::selectOne('select start_value, increment_by from pg_sequences where sequencename = ?', [$sequence]);

    expect((int) $definition->start_value)->toBe(1001)->and((int) $definition->increment_by)->toBe(1);

    $first = DB::selectOne('select nextval(?::regclass) as n', [$sequence])->n;
    $second = DB::selectOne('select nextval(?::regclass) as n', [$sequence])->n;

    expect($first)->toBeGreaterThanOrEqual(1001)->and($second)->toBe($first + 1);
});

it('checks order OCC version, order_changes, and order_change_actions constraints', function (): void {
    $order = slsOrder();

    expect(slsSqlState(static fn () => slsUpdate('orders', 'id', $order, ['version' => 0])))->toBe(SLS_CHECK)
        ->and(DB::table(slsTable('orders'))->where('id', $order)->value('version'))->toBe(1);

    $changeId = Uuid7::generate();
    $insertChange = static fn (array $extra = []): Closure => static fn () => slsInsert('order_changes', [
        'id' => Uuid7::generate(),
        'order_id' => $order,
        'base_order_version' => 1,
        'currency' => 'RUB',
        ...$extra,
    ]);

    expect(slsSqlState($insertChange(['id' => $changeId])))->toBeNull()
        ->and(slsSqlState($insertChange(['status' => 'pending'])))->toBe(SLS_CHECK)
        ->and(slsSqlState($insertChange(['base_order_version' => 0])))->toBe(SLS_CHECK)
        ->and(slsSqlState($insertChange(['status' => 'confirmed'])))->toBe(SLS_CHECK)
        ->and(slsSqlState($insertChange(['status' => 'confirmed', 'confirmed_order_version' => 1, 'confirmed_at' => SALES_NOW])))->toBe(SLS_CHECK)
        ->and(slsSqlState($insertChange(['status' => 'confirmed', 'confirmed_order_version' => 2, 'confirmed_at' => SALES_NOW])))->toBeNull()
        ->and(slsSqlState($insertChange(['status' => 'cancelled'])))->toBe(SLS_CHECK)
        ->and(slsSqlState($insertChange(['status' => 'cancelled', 'cancelled_at' => SALES_NOW])))->toBeNull()
        ->and(slsSqlState($insertChange(['currency' => 'rub'])))->toBe(SLS_CHECK)
        ->and(slsSqlState($insertChange(['order_id' => Uuid7::generate()])))->toBe(SLS_FOREIGN);

    $insertAction = static fn (array $extra = []): Closure => static fn () => slsInsert('order_change_actions', [
        'id' => Uuid7::generate(),
        'order_change_id' => $changeId,
        'position' => 1,
        'type' => 'add_line',
        'payload' => '{}',
        'created_at' => SALES_NOW,
        ...$extra,
    ]);

    expect(slsSqlState($insertAction()))->toBeNull()
        ->and(slsSqlState($insertAction()))->toBe(SLS_UNIQUE)
        ->and(slsSqlState($insertAction(['position' => 0])))->toBe(SLS_CHECK)
        ->and(slsSqlState($insertAction(['position' => 2, 'type' => 'unknown'])))->toBe(SLS_CHECK)
        ->and(slsSqlState($insertAction(['position' => 2, 'payload' => '[]'])))->toBe(SLS_CHECK)
        ->and(slsSqlState($insertAction(['order_change_id' => Uuid7::generate(), 'position' => 2])))->toBe(SLS_FOREIGN);
});
