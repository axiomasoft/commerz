<?php

declare(strict_types=1);

use Commerz\Context\CommerceContext;
use Commerz\Errors\CommerzException;
use Commerz\Identity\IdempotencyKey;
use Commerz\Identity\SellableRef;
use Commerz\Identity\Uuid7;
use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Operations\Contracts\Transactions;
use Commerz\Ports\Delivery\Data\Address;
use Commerz\Ports\Sellables\Data\SellableSnapshot;
use Commerz\Ports\Sellables\Enums\SellableKind;
use Commerz\Sales\Carts\Contracts\Carts;
use Commerz\Sales\Carts\Data\AddCartLine;
use Commerz\Sales\Carts\Data\CartId;
use Commerz\Sales\Carts\Data\CartView;
use Commerz\Sales\Carts\Data\CreateCart;
use Commerz\Sales\Carts\Data\SetCartAddress;
use Commerz\Sales\Carts\Enums\AddressRole;
use Commerz\Sales\Checkout\Contracts\Checkout;
use Commerz\Sales\Checkout\Data\PlaceOrder;
use Commerz\Sales\Common\Internal\OrderLedger;
use Commerz\Sales\Common\Internal\OrderLocks;
use Commerz\Sales\Common\Internal\OrderStatus;
use Commerz\Sales\Configuration\SalesOptions;
use Commerz\Sales\Fulfillments\Contracts\Fulfillments;
use Commerz\Sales\OrderChanges\Contracts\OrderChanges;
use Commerz\Sales\Orders\Contracts\Orders;
use Commerz\Sales\Orders\Data\OrderLineId;
use Commerz\Sales\Orders\Data\OrderView;
use Commerz\Sales\Payments\Contracts\Payments;
use Commerz\Sales\Payments\Data\InitiatePayment;
use Commerz\Sales\Payments\Data\PaymentView;
use Commerz\Sales\Refunds\Contracts\Refunds;
use Commerz\Sales\Returns\Contracts\Returns;
use Commerz\Sales\Sales;
use Commerz\Sales\Tests\Fixtures\RecordingGateway;
use Commerz\Sales\Tests\Fixtures\SalesFixturePlugin;
use Commerz\Sales\Tests\TestCase as SalesTestCase;
use Commerz\Schema\TableNames;
use Commerz\Stores\Contracts\Channels;
use Commerz\Stores\Contracts\Stores;
use Commerz\Stores\Data\ChannelId;
use Commerz\Stores\Data\CreateChannel;
use Commerz\Stores\Data\CreateStore;
use Commerz\Testkit\Fakes\FakeSellables;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\PendingCommand;

/**
 * The fixture plugin goes in before the registry is compiled (the migrations already resolve it); without the inventory
 * package stock is unlimited unless `Commerce::fake()` replaces it.
 */
trait SlsEnvironment
{
    /** @param  Application  $app */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('commerz.plugins', [SalesFixturePlugin::class]);
        $app['config']->set('commerz.stock', 'unlimited');
    }
}

abstract class SlsEnvironmentHarness extends SalesTestCase
{
    use SlsEnvironment;
}

function slsCarts(): Carts
{
    return app(Carts::class);
}

function slsCheckout(): Checkout
{
    return app(Checkout::class);
}

function slsOrders(): Orders
{
    return app(Orders::class);
}

function slsOrderChanges(): OrderChanges
{
    return app(OrderChanges::class);
}

function slsPayments(): Payments
{
    return app(Payments::class);
}

function slsFulfillments(): Fulfillments
{
    return app(Fulfillments::class);
}

function slsReturns(): Returns
{
    return app(Returns::class);
}

function slsRefunds(): Refunds
{
    return app(Refunds::class);
}

/**
 * Config of `commerz-sales`, applied to the modules resolved from now on.
 *
 * @param  array<string, array<string, mixed>>  $sections  e.g. ['checkout' => ['require_delivery' => false]]
 */
function slsConfig(array $sections): void
{
    foreach ($sections as $section => $values) {
        $current = config("commerz-sales.{$section}");

        if (! is_array($current)) {
            $current = [];
        }

        /** @var array<string, mixed> $current */
        config(["commerz-sales.{$section}" => $values + $current]);
    }

    foreach ([SalesOptions::class, Carts::class, Checkout::class, Orders::class, OrderChanges::class, Payments::class, Fulfillments::class, Returns::class, Refunds::class, Sales::class] as $abstract) {
        app()->forgetInstance($abstract);
    }
}

/** An active channel of a store numbering its orders with `$prefix`, selling in RUB and USD. */
function slsChannel(string $code = 'web', string $prefix = 'W-'): ChannelId
{
    $store = app(Stores::class)->create(new CreateStore("store-{$code}", "Store {$code}", Currency::of('RUB'), $prefix));

    return app(Channels::class)->create(new CreateChannel($store->id, $code, "Channel {$code}", [Currency::of('RUB'), Currency::of('USD')]))->id;
}

function slsContext(ChannelId $channel, string $currency = 'RUB'): CommerceContext
{
    return CommerceContext::for($channel, Currency::of($currency), 'ru');
}

function slsSnapshot(string $ref, string $price = '100.00', SellableKind $kind = SellableKind::Physical, ?string $name = null, bool $purchasable = true): SellableSnapshot
{
    $snapshot = FakeSellables::snapshot($ref, Money::of($price, 'RUB'), $kind, $name);

    return new SellableSnapshot(
        $snapshot->ref,
        $snapshot->sku,
        $snapshot->name,
        $snapshot->kind,
        $snapshot->requiresShipping,
        $purchasable,
        $purchasable ? null : 'discontinued',
        ['Colour' => 'Red'],
        $snapshot->basePrice,
        'standard',
        $kind === SellableKind::Physical ? 500 : null,
    );
}

function slsAddress(): Address
{
    return new Address('RU', 'RU-MOW', 'Moscow', '101000', ['Tverskaya 1'], 'Ivan', '+70000000000');
}

/**
 * A cart of the channel with the lines and, when any line ships, a shipping address.
 *
 * @param  array<string, int>  $lines  sellable ref => quantity
 */
function slsCartWith(ChannelId $channel, array $lines, bool $address = true): CartView
{
    $cart = slsCarts()->create(new CreateCart(slsContext($channel)));

    foreach ($lines as $ref => $quantity) {
        $cart = slsCarts()->addLine(new AddCartLine($cart->id, SellableRef::fromString($ref), $quantity));
    }

    return $address ? slsCarts()->setAddress(new SetCartAddress($cart->id, AddressRole::Shipping, slsAddress())) : $cart;
}

/** Places the cart at the total it quotes to now. */
function slsPlace(CartView|CartId $cart, ?string $key = null): OrderView
{
    $id = $cart instanceof CartView ? $cart->id : $cart;

    return slsCheckout()->place(new PlaceOrder($id, slsCarts()->quote($id)->total, $key === null ? null : IdempotencyKey::of($key)));
}

function slsLedger(): OrderLedger
{
    return app(OrderLedger::class);
}

/** Refreshes the states of the order under its lock, as every module does after changing its ledger or money. */
function slsRefresh(OrderView $order): OrderView
{
    app(Transactions::class)->run(static fn () => app(OrderStatus::class)->refresh(app(OrderLocks::class)->lock($order->id)));

    return slsOrders()->find($order->id);
}

/** The id of the order line placed from `$sellable`. */
function slsLine(OrderView $order, string $sellable): OrderLineId
{
    foreach ($order->lines as $line) {
        if ((string) $line->sellable === $sellable) {
            return $line->id;
        }
    }

    throw new LogicException("Order '{$order->id->value}' has no line of {$sellable}.");
}

/**
 * A payment of the order written straight into its table, for tests that run before the payments module or that need
 * a payment in a given status; the order is not refreshed.
 */
function slsOrderPayment(OrderView $order, string $status, int $amountMinor, int $capturedMinor = 0, int $authorizedMinor = 0): string
{
    $id = Uuid7::generate();
    $now = now('UTC')->format('Y-m-d H:i:s.uP');

    DB::table(app(TableNames::class)->table('payments'))->insert([
        'id' => $id,
        'order_id' => $order->id->value,
        'gateway' => 'manual',
        'reference' => 'payment:'.$id,
        'status' => $status,
        'currency' => $order->currency->code,
        'amount_minor' => $amountMinor,
        'authorized_minor' => $authorizedMinor,
        'captured_minor' => $capturedMinor,
        'fingerprint' => str_repeat('0', 64),
        'claimed_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return $id;
}

/**
 * Payments call their gateway outside any transaction, so their tests cannot run inside the transaction of
 * `RefreshDatabase`: they commit, and the sales tables are emptied after each test (`slsTruncate`).
 */
function slsPending(mixed $command): PendingCommand
{
    if (! $command instanceof PendingCommand) {
        throw new RuntimeException('Expected a pending artisan command.');
    }

    return $command;
}

function slsCommitted(): void
{
    if (Artisan::call('migrate') !== 0) {
        throw new RuntimeException('migrate failed.');
    }

    slsTruncate();
    RecordingGateway::reset();
}

function slsTruncate(): void
{
    DB::statement('truncate cmz_payment_captures, cmz_payment_sessions, cmz_payment_collections, cmz_payment_events, cmz_refunds, cmz_payments, cmz_return_lines, cmz_returns,'
        .' cmz_fulfillment_order_lines, cmz_fulfillment_orders,'
        .' cmz_fulfillment_lines, cmz_fulfillments, cmz_order_change_actions, cmz_order_changes, cmz_order_events,'
        .' cmz_order_lines, cmz_orders, cmz_cart_lines,'
        .' cmz_carts, cmz_channels, cmz_stores, cmz_outbox_messages, cmz_operation_receipts cascade');
}

/** @return array<string, mixed> */
function slsJsonObject(mixed $payload): array
{
    if (! is_string($payload)) {
        throw new RuntimeException('Expected a JSON document.');
    }

    $decoded = json_decode($payload, true);

    if (! is_array($decoded)) {
        throw new RuntimeException('Expected a JSON object.');
    }

    /** @var array<string, mixed> $decoded */
    return $decoded;
}

/** @param  array<string, scalar>  $meta */
function slsPay(OrderView $order, string $gateway = 'manual', ?Money $amount = null, array $meta = [], ?string $key = null): PaymentView
{
    return slsPayments()->initiate(new InitiatePayment($order->id, $gateway, $amount, $meta, $key === null ? null : IdempotencyKey::of($key)));
}

/**
 * What a call ended with, as a string a forked process can return: its own string, or the code of its error.
 *
 * @param  Closure(): string  $call
 */
function slsOutcome(Closure $call): string
{
    try {
        return $call();
    } catch (CommerzException $e) {
        return $e->code();
    }
}
