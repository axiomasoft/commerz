<?php

declare(strict_types=1);

use Commerz\Context\CommerceContext;
use Commerz\Identity\IdempotencyKey;
use Commerz\Identity\SellableRef;
use Commerz\Money\Currency;
use Commerz\Money\Money;
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
use Commerz\Sales\Common\Internal\SalesConfig;
use Commerz\Sales\Orders\Contracts\Orders;
use Commerz\Sales\Orders\Data\OrderView;
use Commerz\Sales\Sales;
use Commerz\Sales\Tests\Fixtures\SalesFixturePlugin;
use Commerz\Stores\Contracts\Channels;
use Commerz\Stores\Contracts\Stores;
use Commerz\Stores\Data\ChannelId;
use Commerz\Stores\Data\CreateChannel;
use Commerz\Stores\Data\CreateStore;
use Commerz\Testkit\Fakes\FakeSellables;
use Illuminate\Foundation\Application;

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

/**
 * Config of `commerz-sales`, applied to the modules resolved from now on.
 *
 * @param  array<string, array<string, mixed>>  $sections  e.g. ['checkout' => ['require_delivery' => false]]
 */
function slsConfig(array $sections): void
{
    foreach ($sections as $section => $values) {
        config(["commerz-sales.{$section}" => $values + config("commerz-sales.{$section}")]);
    }

    foreach ([SalesConfig::class, Carts::class, Checkout::class, Orders::class, Sales::class] as $abstract) {
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
