<?php

declare(strict_types=1);

use Commerz\Configuration\CommerzOptions;
use Commerz\Context\CommerceContext;
use Commerz\Errors\IdempotencyConflict;
use Commerz\Identity\IdempotencyKey;
use Commerz\Identity\SellableRef;
use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Ports\Delivery\Data\Address;
use Commerz\Ports\Delivery\Data\DeliveryOption;
use Commerz\Ports\Sellables\Data\SellableSnapshot;
use Commerz\Ports\Sellables\Enums\SellableKind;
use Commerz\Ports\Stock\Data\RestockLine;
use Commerz\Ports\Stock\Data\RestockStock;
use Commerz\Ports\Stock\Data\WriteOffLine;
use Commerz\Ports\Stock\Data\WriteOffStock;
use Commerz\Quotes\Contracts\CartTransformRule;
use Commerz\Quotes\Data\ExpandedComponent;
use Commerz\Quotes\Data\LineExpand;
use Commerz\Quotes\Data\LinesMerge;
use Commerz\Quotes\Data\MergeTargetLine;
use Commerz\Quotes\Data\QuoteInput;
use Commerz\Quotes\Exceptions\QuoteFailed;
use Commerz\Sales\Carts\Contracts\Carts;
use Commerz\Sales\Carts\Data\AddCartLine;
use Commerz\Sales\Carts\Data\CreateCart;
use Commerz\Sales\Carts\Data\EnterCode;
use Commerz\Sales\Carts\Data\SetCartAddress;
use Commerz\Sales\Carts\Enums\AddressRole;
use Commerz\Sales\Checkout\Contracts\Checkout;
use Commerz\Sales\Checkout\Data\PlaceOrder;
use Commerz\Sales\Common\Data\LineQuantity;
use Commerz\Sales\Configuration\SalesOptions;
use Commerz\Sales\Exchanges\Contracts\Exchanges;
use Commerz\Sales\Exchanges\Data\CancelOrderClaim;
use Commerz\Sales\Exchanges\Data\CancelOrderExchange;
use Commerz\Sales\Exchanges\Data\ClaimItem;
use Commerz\Sales\Exchanges\Data\CompleteOrderExchange;
use Commerz\Sales\Exchanges\Data\CreateOrderClaim;
use Commerz\Sales\Exchanges\Data\CreateOrderExchange;
use Commerz\Sales\Exchanges\Data\ExchangeOutboundItem;
use Commerz\Sales\Exchanges\Data\FulfillOrderClaim;
use Commerz\Sales\Exchanges\Data\ListOrderClaims;
use Commerz\Sales\Exchanges\Data\ListOrderExchanges;
use Commerz\Sales\Exchanges\Enums\ClaimKind;
use Commerz\Sales\Exchanges\Enums\ClaimStatus;
use Commerz\Sales\Exchanges\Enums\ExchangeSettlement;
use Commerz\Sales\Exchanges\Enums\ExchangeStatus;
use Commerz\Sales\Exchanges\Exceptions\ClaimNotOpen;
use Commerz\Sales\Exchanges\Exceptions\ExchangeNotOpen;
use Commerz\Sales\Facades\Sales as SalesFacade;
use Commerz\Sales\Fulfillments\Contracts\Fulfillments;
use Commerz\Sales\Fulfillments\Data\CreateFulfillment;
use Commerz\Sales\Fulfillments\Data\MarkFulfillmentDelivered;
use Commerz\Sales\Fulfillments\Data\ShipFulfillment;
use Commerz\Sales\Fulfillments\Enums\FulfillmentStatus;
use Commerz\Sales\Numbering\Contracts\OrderNumberGenerator;
use Commerz\Sales\Numbering\Data\OrderNumbering;
use Commerz\Sales\Orders\Contracts\Orders;
use Commerz\Sales\Orders\Data\OrderView;
use Commerz\Sales\Returns\Contracts\Returns;
use Commerz\Sales\Returns\Data\ReceiveReturn;
use Commerz\Sales\Returns\Data\RequestReturn;
use Commerz\Sales\Returns\Data\ReturnLineDisposition;
use Commerz\Sales\Returns\Enums\ReturnDisposition;
use Commerz\Sales\Returns\Enums\ReturnReason;
use Commerz\Sales\Returns\Enums\ReturnStatus;
use Commerz\Sales\Sales;
use Commerz\Sales\Tests\Fixtures\SalesFixturePlugin;
use Commerz\Schema\TableNames;
use Commerz\Stores\Contracts\Channels;
use Commerz\Stores\Contracts\Stores;
use Commerz\Stores\Data\ChannelId;
use Commerz\Stores\Data\CreateChannel;
use Commerz\Stores\Data\CreateStore;
use Commerz\Testkit\Commerce;
use Commerz\Testkit\Fakes\FakeSellables;
use Commerz\Testkit\Fakes\FakeStock;
use Illuminate\Database\Connection;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\DB;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Packman\Plugins\Data\Plugin\PluginManifest;
use Packman\Plugins\Laravel\PluginRegistrar;

final class SlsRmaSqliteConnection extends SQLiteConnection
{
    private const array BOOL_COLUMNS = ['prices_include_tax', 'requires_shipping', 'tax_included', 'booking_requested'];

    private static function normalizePgSql(string $query): string
    {
        $normalized = str_replace(['::jsonb', '::text', '::bigint', '::integer', '::int'], '', $query);

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

final class SlsSqliteCounterNumbers implements OrderNumberGenerator
{
    private static int $seq = 2000;

    public function key(): string
    {
        return 'test.sqlite-seq';
    }

    public function next(OrderNumbering $input): string
    {
        self::$seq++;

        return $input->store->orderNumberPrefix.self::$seq;
    }
}

final readonly class SlsCartTransformTestRule implements CartTransformRule
{
    public function key(): string
    {
        return 'sales.test-cart-transform';
    }

    public function transform(QuoteInput $input): array
    {
        if (in_array('FAIL_TRANSFORM', $input->codes, true)) {
            throw new RuntimeException('blocked cart transform failure');
        }

        $operations = [];
        $currency = $input->context->currency;

        $hoodieLine = null;
        $joggerLine = null;

        foreach ($input->lines as $line) {
            $ref = (string) $line->sellable->ref;

            if ($ref === 'fake:bundle-kit') {
                $operations[] = new LineExpand($line->lineId, [
                    new ExpandedComponent(SellableRef::of('fake', 'shirt-m'), 1, Money::of('600.00', $currency)),
                    new ExpandedComponent(SellableRef::of('fake', 'cap'), 2, Money::of('200.00', $currency)),
                ]);
            } elseif ($ref === 'fake:hoodie') {
                $hoodieLine = $line;
            } elseif ($ref === 'fake:joggers') {
                $joggerLine = $line;
            }
        }

        if ($hoodieLine !== null && $joggerLine !== null) {
            $pairs = min($hoodieLine->quantity, $joggerLine->quantity);
            $operations[] = new LinesMerge(
                targets: [
                    new MergeTargetLine($hoodieLine->lineId, $pairs),
                    new MergeTargetLine($joggerLine->lineId, $pairs),
                ],
                parentSellable: SellableRef::of('fake', 'tracksuit-set'),
                quantity: $pairs,
                fixedUnitPrice: Money::of('1500.00', $currency),
            );
        }

        return $operations;
    }
}

final class SlsRmaTestPlugin implements ExtensionPlugin
{
    public function manifest(): PluginManifest
    {
        return new PluginManifest('test.sls-rma-transform', '1.0.0', ['commerz.core', 'commerz.sales']);
    }

    public function register(PluginRegistrar $registrar): void
    {
        $registrar->contribute(OrderNumberGenerator::KIND, SlsSqliteCounterNumbers::class);
        $registrar->contribute(CartTransformRule::KIND, new SlsCartTransformTestRule);
    }
}

function rmaSnap(string $ref, string $price, int $weight = 400, SellableKind $kind = SellableKind::Physical): SellableSnapshot
{
    $s = FakeSellables::snapshot($ref, Money::of($price, 'RUB'), $kind);

    return new SellableSnapshot(
        ref: $s->ref,
        sku: $s->sku,
        name: $s->name,
        kind: $kind,
        requiresShipping: $kind === SellableKind::Physical,
        basePrice: $s->basePrice,
        taxClass: 'standard',
        weightGrams: $kind === SellableKind::Physical ? $weight : null,
    );
}

beforeEach(function (): void {
    Connection::resolverFor('sqlite', static function ($connection, $database, $prefix, array $config) {
        if (($config['commerz_compat'] ?? false) === true) {
            return new SlsRmaSqliteConnection($connection, $database, $prefix, $config);
        }

        return new SQLiteConnection($connection, $database, $prefix, $config);
    });

    config()->set('commerz.plugins', [SalesFixturePlugin::class, SlsRmaTestPlugin::class]);
    config()->set('commerz.stock', 'unlimited');
    config()->set('commerz-sales.checkout.require_delivery', false);
    config()->set('commerz-sales.checkout.number_generator', 'test.sqlite-seq');

    config()->set('database.connections.sls_rma_sqlite', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'commerz_compat' => true,
    ]);
    config()->set('commerz.connection', 'sls_rma_sqlite');
    DB::purge('sls_rma_sqlite');

    foreach ([
        CommerzOptions::class,
        TableNames::class,
        Stores::class,
        Channels::class,
        SalesOptions::class,
        Carts::class,
        Checkout::class,
        Orders::class,
        Fulfillments::class,
        Returns::class,
        Exchanges::class,
        Sales::class,
    ] as $abstract) {
        app()->forgetInstance($abstract);
    }

    $db = DB::connection('sls_rma_sqlite');
    $db->statement('PRAGMA foreign_keys = ON');

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
            default_locale text not null default 'ru',
            prices_include_tax integer not null default 0,
            status text not null default 'active',
            created_at text not null,
            updated_at text not null
        )
    SQL);

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
            created_at text not null,
            updated_at text not null
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
            created_at text not null,
            updated_at text not null,
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
            created_at text not null,
            updated_at text not null,
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

    $db->statement(<<<'SQL'
        create table cmz_payments (
            id text primary key,
            order_id text not null references cmz_orders(id),
            gateway text not null,
            reference text not null unique,
            status text not null,
            currency text not null,
            amount_minor integer not null,
            authorized_minor integer not null default 0,
            captured_minor integer not null default 0,
            refunded_minor integer not null default 0,
            fingerprint text not null,
            attempts integer not null default 1,
            claimed_at text not null,
            external_id text,
            action_url text,
            meta text not null default '{}',
            error_code text,
            error_message text,
            created_at text not null,
            updated_at text not null
        )
    SQL);

    $db->statement(<<<'SQL'
        create table cmz_refunds (
            id text primary key,
            order_id text not null references cmz_orders(id),
            payment_id text not null references cmz_payments(id),
            return_id text,
            gateway text not null,
            reference text not null unique,
            status text not null,
            currency text not null,
            amount_minor integer not null,
            fingerprint text not null,
            attempts integer not null default 1,
            claimed_at text not null,
            external_id text,
            reason text,
            error_code text,
            error_message text,
            created_at text not null,
            updated_at text not null
        )
    SQL);

    $db->statement(<<<'SQL'
        create table cmz_fulfillments (
            id text primary key,
            order_id text not null references cmz_orders(id),
            status text not null default 'created',
            location_code text,
            carrier text,
            method_code text,
            tracking text,
            label_ref text,
            booking_requested integer not null default 0,
            shipped_at text,
            delivered_at text,
            created_at text not null,
            updated_at text not null
        )
    SQL);

    $db->statement(<<<'SQL'
        create table cmz_fulfillment_lines (
            fulfillment_id text not null references cmz_fulfillments(id) on delete cascade,
            order_line_id text not null references cmz_order_lines(id),
            quantity integer not null,
            primary key (fulfillment_id, order_line_id)
        )
    SQL);

    $db->statement(<<<'SQL'
        create table cmz_returns (
            id text primary key,
            order_id text not null references cmz_orders(id),
            status text not null default 'requested',
            reason text,
            location_code text,
            received_at text,
            cancelled_at text,
            created_at text not null,
            updated_at text not null
        )
    SQL);

    $db->statement(<<<'SQL'
        create table cmz_return_lines (
            return_id text not null references cmz_returns(id) on delete cascade,
            order_line_id text not null references cmz_order_lines(id),
            quantity integer not null,
            restocked_quantity integer not null default 0,
            primary key (return_id, order_line_id)
        )
    SQL);

    // Execute our actual migration 2026_11_26_500003!
    $migration = require __DIR__.'/../../../database/migrations/2026_11_26_500003_create_exchanges_and_rma_disposition.php';
    $migration->up();

    $this->commerce = Commerce::fake();
    $this->commerce->sellables->add(
        rmaSnap('fake:shirt-m', '1000.00', 300),
        rmaSnap('fake:shirt-l', '1000.00', 320),
        rmaSnap('fake:jacket', '1600.00', 800),
        rmaSnap('fake:cap', '400.00', 150),
        rmaSnap('fake:bundle-kit', '999.00', 0, SellableKind::Service),
        rmaSnap('fake:hoodie', '1100.00', 600),
        rmaSnap('fake:joggers', '800.00', 500),
        rmaSnap('fake:tracksuit-set', '1500.00', 1100),
    );
    $this->commerce->delivery->offer(new DeliveryOption('courier', 'Courier', Money::of('250.00', 'RUB'), 'cdek', 2));

    $pdo = $db->getPdo();
    $pdo->sqliteCreateFunction('hashtext', static fn (string $key): int => crc32($key));
    $pdo->sqliteCreateFunction('pg_advisory_xact_lock', static fn (int $_): int => 1);

    // Open ambient transaction so DatabaseTransactions::run uses savepoints on SQLite
    $db->beginTransaction();

    $store = app(Stores::class)->create(new CreateStore('shop', 'Shop', Currency::of('RUB'), 'ORD-'));
    $this->channel = app(Channels::class)->create(new CreateChannel($store->id, 'web', 'Web', [Currency::of('RUB')]))->id;
});

afterEach(function (): void {
    $db = DB::connection('sls_rma_sqlite');
    while ($db->transactionLevel() > 0) {
        $db->rollBack();
    }
});

/** @param array<string, int> $items */
function rmaPlaceAndFulfill(ChannelId $channel, array $items): OrderView
{
    $carts = SalesFacade::carts();
    $cart = $carts->create(new CreateCart(CommerceContext::for($channel, Currency::of('RUB'), 'ru')));

    foreach ($items as $ref => $qty) {
        $cart = $carts->addLine(new AddCartLine($cart->id, SellableRef::fromString($ref), $qty));
    }

    $address = new Address('RU', 'RU-MOW', 'Moscow', '101000', ['Arbat 10'], 'Olga', '+79990001122');
    $carts->setAddress(new SetCartAddress($cart->id, AddressRole::Shipping, $address));

    $quote = $carts->quote($cart->id);
    $order = SalesFacade::checkout()->place(new PlaceOrder($cart->id, $quote->total));

    $fulfillLines = [];
    foreach ($order->lines as $line) {
        $fulfillLines[] = new LineQuantity($line->id, $line->quantity);
    }

    SalesFacade::fulfillments()->create(new CreateFulfillment($order->id, $fulfillLines, locationCode: FakeStock::LOCATION));

    return SalesFacade::orders()->find($order->id);
}

it('wires CartTransform rules (LineExpand, LinesMerge, BlockOnFailure) into Carts::quote, deliveryOptions, and Checkout::place', function (): void {
    $carts = SalesFacade::carts();
    $address = new Address('RU', 'RU-MOW', 'Moscow', '101000', ['Arbat 10'], 'Olga', '+79990001122');

    // 1. LineExpand: virtual bundle-kit expands into 1 × shirt-m (600, 300g) + 2 × cap (200 each, 150g each) = 1000.00, 600g
    $this->commerce->stock->limit(SellableRef::of('fake', 'shirt-m'), 10);
    $this->commerce->stock->limit(SellableRef::of('fake', 'cap'), 10);

    $bundleCart = $carts->create(new CreateCart(CommerceContext::for($this->channel, Currency::of('RUB'), 'ru')));
    $carts->addLine(new AddCartLine($bundleCart->id, SellableRef::fromString('fake:bundle-kit'), 2));
    $carts->setAddress(new SetCartAddress($bundleCart->id, AddressRole::Shipping, $address));

    $options = $carts->deliveryOptions($bundleCart->id);
    // Parcel weight must reflect the expanded physical components: 2 * (300 + 2*150) = 1200g
    expect($options)->toHaveCount(1)
        ->and($this->commerce->delivery->requests[0]->parcel->weightGrams)->toBe(1200);

    $bundleQuote = $carts->quote($bundleCart->id);
    expect($bundleQuote->lines)->toHaveCount(2)
        ->and($bundleQuote->totals->subtotal->toDecimal())->toBe('2000.00')
        ->and($bundleQuote->total->toDecimal())->toBe('2000.00');

    $placedBundleOrder = SalesFacade::checkout()->place(new PlaceOrder($bundleCart->id, $bundleQuote->total));
    expect($placedBundleOrder->lines)->toHaveCount(2)
        ->and((string) $placedBundleOrder->lines[0]->sellable)->toBe('fake:shirt-m')
        ->and($placedBundleOrder->lines[0]->quantity)->toBe(2)
        ->and((string) $placedBundleOrder->lines[1]->sellable)->toBe('fake:cap')
        ->and($placedBundleOrder->lines[1]->quantity)->toBe(4)
        ->and($this->commerce->stock->onHand(SellableRef::of('fake', 'shirt-m')))->toBe(10)
        ->and($this->commerce->stock->available(SellableRef::of('fake', 'shirt-m')))->toBe(8)
        ->and($this->commerce->stock->available(SellableRef::of('fake', 'cap')))->toBe(6);

    // 2. LinesMerge: hoodie + joggers merge into tracksuit-set (1500.00)
    $mergeCart = $carts->create(new CreateCart(CommerceContext::for($this->channel, Currency::of('RUB'), 'ru')));
    $carts->addLine(new AddCartLine($mergeCart->id, SellableRef::fromString('fake:hoodie'), 1));
    $carts->addLine(new AddCartLine($mergeCart->id, SellableRef::fromString('fake:joggers'), 1));
    $carts->setAddress(new SetCartAddress($mergeCart->id, AddressRole::Shipping, $address));

    $mergeQuote = $carts->quote($mergeCart->id);
    expect($mergeQuote->lines)->toHaveCount(1)
        ->and((string) $mergeQuote->lines[0]->sellable->ref)->toBe('fake:tracksuit-set')
        ->and($mergeQuote->totals->subtotal->toDecimal())->toBe('1500.00');

    // 3. FailurePolicy::BlockOnFailure blocks Carts::quote when transform rule fails
    $carts->enterCode(new EnterCode($mergeCart->id, 'FAIL_TRANSFORM'));
    expect(fn () => $carts->quote($mergeCart->id))->toThrow(QuoteFailed::class);
});

it('handles ReturnDisposition::Restock vs ReturnDisposition::DamagedWriteOff and ReturnReason on Returns::receive (INV-INV-002)', function (): void {
    $this->commerce->stock->limit(SellableRef::of('fake', 'shirt-m'), 10);
    $order = rmaPlaceAndFulfill($this->channel, ['fake:shirt-m' => 3]);
    $lineId = $order->lines[0]->id;

    // 10 initial - 3 fulfilled = 7 onHand
    expect($this->commerce->stock->onHand(SellableRef::of('fake', 'shirt-m')))->toBe(7);

    // DamagedWriteOff: NEVER increases sellable on_hand
    $damagedReturn = SalesFacade::returns()->request(new RequestReturn(
        order: $order->id,
        lines: [new LineQuantity($lineId, 2)],
        reason: ReturnReason::DamagedInTransit,
    ));

    expect($damagedReturn->returnReason)->toBe(ReturnReason::DamagedInTransit);

    $receivedDamaged = SalesFacade::returns()->receive(new ReceiveReturn(
        return: $damagedReturn->id,
        locationCode: FakeStock::LOCATION,
        disposition: ReturnDisposition::DamagedWriteOff,
        reason: ReturnReason::DamagedInTransit,
    ));

    expect($receivedDamaged->status)->toBe(ReturnStatus::Received)
        ->and($receivedDamaged->disposition)->toBe(ReturnDisposition::DamagedWriteOff)
        ->and($receivedDamaged->returnReason)->toBe(ReturnReason::DamagedInTransit)
        ->and($receivedDamaged->lines[0]->restocked)->toBe(0)
        ->and($receivedDamaged->lines[0]->writtenOff)->toBe(2)
        ->and($receivedDamaged->lines[0]->disposition)->toBe(ReturnDisposition::DamagedWriteOff)
        ->and($this->commerce->stock->onHand(SellableRef::of('fake', 'shirt-m')))->toBe(7)
        ->and($this->commerce->stock->restocked)->toBe([])
        ->and($this->commerce->stock->writtenOff)->toEqual([
            new WriteOffStock(
                'return:'.$damagedReturn->id->value.':write_off',
                $this->channel,
                [new WriteOffLine(SellableRef::of('fake', 'shirt-m'), 2)],
                FakeStock::LOCATION,
                'damaged_in_transit',
            ),
        ]);

    // Restock: increases sellable on_hand by 1 -> 8
    $restockReturn = SalesFacade::returns()->request(new RequestReturn(
        order: $order->id,
        lines: [new LineQuantity($lineId, 1)],
        reason: ReturnReason::ChangedMind,
    ));

    $receivedRestock = SalesFacade::returns()->receive(new ReceiveReturn(
        return: $restockReturn->id,
        locationCode: FakeStock::LOCATION,
        disposition: ReturnDisposition::Restock,
    ));

    expect($receivedRestock->disposition)->toBe(ReturnDisposition::Restock)
        ->and($receivedRestock->lines[0]->restocked)->toBe(1)
        ->and($receivedRestock->lines[0]->writtenOff)->toBe(0)
        ->and($this->commerce->stock->onHand(SellableRef::of('fake', 'shirt-m')))->toBe(8)
        ->and($this->commerce->stock->restocked)->toEqual([
            new RestockStock(
                'return:'.$restockReturn->id->value,
                $this->channel,
                [new RestockLine(SellableRef::of('fake', 'shirt-m'), 1)],
                FakeStock::LOCATION,
            ),
        ]);
});

it('supports split per-line ReturnLineDisposition on a single return', function (): void {
    $this->commerce->stock->limit(SellableRef::of('fake', 'shirt-m'), 10);
    $order = rmaPlaceAndFulfill($this->channel, ['fake:shirt-m' => 3]);
    $lineId = $order->lines[0]->id;

    $return = SalesFacade::returns()->request(new RequestReturn($order->id, [new LineQuantity($lineId, 3)], ReturnReason::Defective));

    $received = SalesFacade::returns()->receive(new ReceiveReturn(
        return: $return->id,
        locationCode: FakeStock::LOCATION,
        dispositions: [
            new ReturnLineDisposition($lineId, 1, ReturnDisposition::Restock, ReturnReason::ChangedMind),
            new ReturnLineDisposition($lineId, 2, ReturnDisposition::DamagedWriteOff, ReturnReason::Defective),
        ],
    ));

    // Started 10 -> 7 after fulfillment -> +1 restock, 0 from 2 written off = 8
    expect($received->lines[0]->restocked)->toBe(1)
        ->and($received->lines[0]->writtenOff)->toBe(2)
        ->and($this->commerce->stock->onHand(SellableRef::of('fake', 'shirt-m')))->toBe(8)
        ->and($this->commerce->stock->writtenOff)->toHaveCount(1);
});

it('executes OrderExchange combining inbound Return and outbound Fulfillment with positive, negative, and even netDelta', function (): void {
    $this->commerce->stock->limit(SellableRef::of('fake', 'shirt-m'), 10);
    $this->commerce->stock->limit(SellableRef::of('fake', 'shirt-l'), 5);
    $this->commerce->stock->limit(SellableRef::of('fake', 'jacket'), 5);
    $this->commerce->stock->limit(SellableRef::of('fake', 'cap'), 5);

    // Order has 3 × shirt-m (1000 + 20% VAT = 1200.00 each)
    $order = rmaPlaceAndFulfill($this->channel, ['fake:shirt-m' => 3]);
    $shirtLine = $order->lines[0]->id;

    // 1. Upgrade Exchange: return 1 × shirt-m (1200.00 credit) -> outbound 1 × jacket (1600 + 20% VAT = 1920.00) => netDelta = +720.00
    $upgrade = SalesFacade::exchanges()->createExchange(new CreateOrderExchange(
        order: $order->id,
        inboundLines: [new LineQuantity($shirtLine, 1)],
        outboundItems: [new ExchangeOutboundItem(SellableRef::of('fake', 'jacket'), 1)],
        reason: ReturnReason::SizeOrFit,
        note: 'Upgrade to jacket',
        idempotencyKey: IdempotencyKey::of('ex-upgrade-1'),
    ));

    // Idempotent replay
    $replayed = SalesFacade::exchanges()->createExchange(new CreateOrderExchange(
        order: $order->id,
        inboundLines: [new LineQuantity($shirtLine, 1)],
        outboundItems: [new ExchangeOutboundItem(SellableRef::of('fake', 'jacket'), 1)],
        reason: ReturnReason::SizeOrFit,
        note: 'Upgrade to jacket',
        idempotencyKey: IdempotencyKey::of('ex-upgrade-1'),
    ));
    expect($replayed->id->equals($upgrade->id))->toBeTrue()
        ->and(fn () => SalesFacade::exchanges()->createExchange(new CreateOrderExchange(
            order: $order->id,
            inboundLines: [new LineQuantity($shirtLine, 2)],
            outboundItems: [new ExchangeOutboundItem(SellableRef::of('fake', 'jacket'), 1)],
            idempotencyKey: IdempotencyKey::of('ex-upgrade-1'),
        )))->toThrow(IdempotencyConflict::class);

    expect($upgrade->status)->toBe(ExchangeStatus::Requested)
        ->and($upgrade->settlement)->toBe(ExchangeSettlement::PaymentDue)
        ->and($upgrade->returnTotal->toDecimal())->toBe('1000.00')
        ->and($upgrade->outboundTotal->toDecimal())->toBe('1600.00')
        ->and($upgrade->netDelta->toDecimal())->toBe('600.00')
        ->and($upgrade->amountDue->toDecimal())->toBe('600.00')
        ->and($upgrade->refundDue->toDecimal())->toBe('0.00')
        ->and($this->commerce->stock->available(SellableRef::of('fake', 'jacket')))->toBe(4);

    // Complete upgrade exchange with DamagedWriteOff on the returned shirt-m!
    $completedUpgrade = SalesFacade::exchanges()->completeExchange(new CompleteOrderExchange(
        exchange: $upgrade->id,
        disposition: ReturnDisposition::DamagedWriteOff,
        returnLocationCode: FakeStock::LOCATION,
        fulfillmentLocationCode: FakeStock::LOCATION,
    ));

    expect($completedUpgrade->status)->toBe(ExchangeStatus::Completed)
        ->and($completedUpgrade->fulfillment)->not->toBeNull()
        ->and(SalesFacade::returns()->find($upgrade->return)->status)->toBe(ReturnStatus::Received)
        ->and(SalesFacade::returns()->find($upgrade->return)->disposition)->toBe(ReturnDisposition::DamagedWriteOff)
        // shirt-m stayed at 7 (10 - 3 fulfilled + 0 restocked due to DamagedWriteOff); jacket dropped 5 -> 4
        ->and($this->commerce->stock->onHand(SellableRef::of('fake', 'shirt-m')))->toBe(7)
        ->and($this->commerce->stock->onHand(SellableRef::of('fake', 'jacket')))->toBe(4);

    // Outbound fulfillment works with Fulfillments::ship & markDelivered!
    $shipped = SalesFacade::fulfillments()->ship(new ShipFulfillment($completedUpgrade->fulfillment, 'cdek', 'TRK-EX-1'));
    $delivered = SalesFacade::fulfillments()->markDelivered(new MarkFulfillmentDelivered($shipped->id));
    expect($delivered->status)->toBe(FulfillmentStatus::Delivered);

    // 2. Downgrade Exchange: return 1 × shirt-m (1000.00 credit) -> outbound 1 × cap (400.00) => netDelta = -600.00
    $downgrade = SalesFacade::exchanges()->createExchange(new CreateOrderExchange(
        order: $order->id,
        inboundLines: [new LineQuantity($shirtLine, 1)],
        outboundItems: [new ExchangeOutboundItem(SellableRef::of('fake', 'cap'), 1)],
        reason: ReturnReason::ChangedMind,
    ));

    expect($downgrade->settlement)->toBe(ExchangeSettlement::RefundDue)
        ->and($downgrade->netDelta->toDecimal())->toBe('-600.00')
        ->and($downgrade->amountDue->toDecimal())->toBe('0.00')
        ->and($downgrade->refundDue->toDecimal())->toBe('600.00');

    $completedDowngrade = SalesFacade::exchanges()->completeExchange(new CompleteOrderExchange(
        exchange: $downgrade->id,
        disposition: ReturnDisposition::Restock,
        returnLocationCode: FakeStock::LOCATION,
        fulfillmentLocationCode: FakeStock::LOCATION,
    ));

    expect($completedDowngrade->status)->toBe(ExchangeStatus::Completed)
        ->and($this->commerce->stock->onHand(SellableRef::of('fake', 'shirt-m')))->toBe(8)
        ->and($this->commerce->stock->onHand(SellableRef::of('fake', 'cap')))->toBe(4);

    // 3. Even Swap Exchange + Cancel: return 1 × shirt-m (1200.00) -> 1 × shirt-l (1200.00) => netDelta = 0.00
    $evenSwap = SalesFacade::exchanges()->createExchange(new CreateOrderExchange(
        order: $order->id,
        inboundLines: [new LineQuantity($shirtLine, 1)],
        outboundItems: [new ExchangeOutboundItem(SellableRef::of('fake', 'shirt-l'), 1)],
        reason: ReturnReason::SizeOrFit,
    ));

    expect($evenSwap->settlement)->toBe(ExchangeSettlement::Even)
        ->and($evenSwap->netDelta->toDecimal())->toBe('0.00')
        ->and($this->commerce->stock->available(SellableRef::of('fake', 'shirt-l')))->toBe(4);

    $cancelledSwap = SalesFacade::exchanges()->cancelExchange(new CancelOrderExchange($evenSwap->id));
    expect($cancelledSwap->status)->toBe(ExchangeStatus::Cancelled)
        ->and($this->commerce->stock->available(SellableRef::of('fake', 'shirt-l')))->toBe(5)
        ->and(SalesFacade::returns()->find($evenSwap->return)->status)->toBe(ReturnStatus::Cancelled)
        ->and(fn () => SalesFacade::exchanges()->completeExchange(new CompleteOrderExchange($evenSwap->id)))->toThrow(ExchangeNotOpen::class)
        ->and(SalesFacade::exchanges()->listExchanges(new ListOrderExchanges(order: $order->id))->items)->toHaveCount(3);
});

it('executes OrderClaim for free replacement and reshipment with zero invoice', function (): void {
    $this->commerce->stock->limit(SellableRef::of('fake', 'shirt-m'), 10);
    $this->commerce->stock->limit(SellableRef::of('fake', 'shirt-l'), 5);

    $order = rmaPlaceAndFulfill($this->channel, ['fake:shirt-m' => 2]);
    $originalTotal = $order->total;
    $originalAmountDue = $order->amountDue;
    $shirtLineId = $order->lines[0]->id;

    // 1. Immediate free reshipment of fulfilled order line with zero invoice
    $reshipment = SalesFacade::exchanges()->createClaim(new CreateOrderClaim(
        order: $order->id,
        items: [ClaimItem::orderLine($shirtLineId, 1)],
        kind: ClaimKind::Reshipment,
        reason: ReturnReason::DamagedInTransit,
        note: 'Parcel lost by courier, free reshipment',
        fulfillImmediately: true,
        locationCode: FakeStock::LOCATION,
        idempotencyKey: IdempotencyKey::of('claim-reship-1'),
    ));

    expect($reshipment->status)->toBe(ClaimStatus::Fulfilled)
        ->and($reshipment->kind)->toBe(ClaimKind::Reshipment)
        ->and($reshipment->fulfillment)->not->toBeNull()
        ->and($reshipment->subtotal->toDecimal())->toBe('0.00')
        ->and($reshipment->taxTotal->toDecimal())->toBe('0.00')
        ->and($reshipment->invoiceTotal->toDecimal())->toBe('0.00')
        ->and($reshipment->amountDue->toDecimal())->toBe('0.00')
        ->and($reshipment->lines[0]->total->toDecimal())->toBe('0.00')
        // Stock consumed for free reshipment (10 - 2 initial - 1 reship = 7)
        ->and($this->commerce->stock->onHand(SellableRef::of('fake', 'shirt-m')))->toBe(7)
        // Original order invoice/due untouched!
        ->and(SalesFacade::orders()->find($order->id)->total)->toEqual($originalTotal)
        ->and(SalesFacade::orders()->find($order->id)->amountDue)->toEqual($originalAmountDue);

    // 2. Two-step free replacement claim (reserve -> fulfill or cancel)
    $replacement = SalesFacade::exchanges()->createClaim(new CreateOrderClaim(
        order: $order->id,
        items: [ClaimItem::sellable(SellableRef::of('fake', 'shirt-l'), 1)],
        kind: ClaimKind::Replacement,
        reason: ReturnReason::Defective,
        fulfillImmediately: false,
    ));

    expect($replacement->status)->toBe(ClaimStatus::Created)
        ->and($replacement->fulfillment)->toBeNull()
        ->and($replacement->invoiceTotal->toDecimal())->toBe('0.00')
        ->and($this->commerce->stock->available(SellableRef::of('fake', 'shirt-l')))->toBe(4);

    $fulfilledReplacement = SalesFacade::exchanges()->fulfillClaim(new FulfillOrderClaim($replacement->id, FakeStock::LOCATION));
    expect($fulfilledReplacement->status)->toBe(ClaimStatus::Fulfilled)
        ->and($fulfilledReplacement->fulfillment)->not->toBeNull()
        ->and($this->commerce->stock->onHand(SellableRef::of('fake', 'shirt-l')))->toBe(4)
        ->and(fn () => SalesFacade::exchanges()->cancelClaim(new CancelOrderClaim($replacement->id)))->toThrow(ClaimNotOpen::class);

    // 3. Cancel unfulfilled claim releases reserved stock
    $cancellable = SalesFacade::exchanges()->createClaim(new CreateOrderClaim(
        order: $order->id,
        items: [ClaimItem::sellable(SellableRef::of('fake', 'shirt-l'), 2)],
        fulfillImmediately: false,
    ));
    expect($this->commerce->stock->available(SellableRef::of('fake', 'shirt-l')))->toBe(2);

    $cancelled = SalesFacade::exchanges()->cancelClaim(new CancelOrderClaim($cancellable->id));
    expect($cancelled->status)->toBe(ClaimStatus::Cancelled)
        ->and($this->commerce->stock->available(SellableRef::of('fake', 'shirt-l')))->toBe(4)
        ->and(SalesFacade::exchanges()->listClaims(new ListOrderClaims(order: $order->id))->items)->toHaveCount(3);
});
