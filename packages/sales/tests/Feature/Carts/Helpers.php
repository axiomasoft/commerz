<?php

declare(strict_types=1);

use Commerz\Context\CommerceContext;
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
use Commerz\Sales\Configuration\SalesOptions;
use Commerz\Sales\Sales;
use Commerz\Schema\TableNames;
use Commerz\Stores\Contracts\Channels;
use Commerz\Stores\Contracts\Stores;
use Commerz\Stores\Data\ChannelId;
use Commerz\Stores\Data\CreateChannel;
use Commerz\Stores\Data\CreateStore;
use Commerz\Testkit\Fakes\FakeSellables;
use Illuminate\Support\Facades\DB;

function ctCarts(): Carts
{
    return app(Carts::class);
}

/** Config of the carts section, applied to the modules resolved from now on. @param array<string, mixed> $carts */
function ctConfig(array $carts): void
{
    config(['commerz-sales.carts' => $carts + config('commerz-sales.carts')]);
    app()->forgetInstance(SalesOptions::class);
    app()->forgetInstance(Carts::class);
    app()->forgetInstance(Sales::class);
}

/** An active channel `web` selling in RUB and USD. */
function ctChannel(string $code = 'web'): ChannelId
{
    $store = app(Stores::class)->create(new CreateStore("store-{$code}", "Store {$code}", Currency::of('RUB')));

    return app(Channels::class)->create(new CreateChannel($store->id, $code, "Channel {$code}", [Currency::of('RUB'), Currency::of('USD')]))->id;
}

function ctContext(ChannelId $channel, string $currency = 'RUB'): CommerceContext
{
    return CommerceContext::for($channel, Currency::of($currency), 'ru');
}

function ctCart(?ChannelId $channel = null, string $currency = 'RUB'): CartView
{
    return ctCarts()->create(new CreateCart(ctContext($channel ?? ctChannel(), $currency)));
}

function ctSnapshot(string $ref, string $price = '100.00', SellableKind $kind = SellableKind::Physical, ?int $grams = null, bool $purchasable = true, ?string $reason = null): SellableSnapshot
{
    $snapshot = FakeSellables::snapshot($ref, Money::of($price, 'RUB'), $kind);

    return new SellableSnapshot(
        $snapshot->ref,
        $snapshot->sku,
        $snapshot->name,
        $snapshot->kind,
        $snapshot->requiresShipping,
        $purchasable,
        $reason,
        basePrice: $snapshot->basePrice,
        weightGrams: $grams,
    );
}

function ctAdd(CartView|CartId $cart, string $ref, int $quantity = 1): CartView
{
    $id = $cart instanceof CartView ? $cart->id : $cart;

    return ctCarts()->addLine(new AddCartLine($id, SellableRef::fromString($ref), $quantity));
}

function ctAddress(string $country = 'RU', ?string $region = 'RU-MOW'): Address
{
    return new Address($country, $region, 'Moscow', '101000', ['Tverskaya 1'], 'Ivan', '+70000000000');
}

/** Marks a cart converted behind the module's back, as the placement will. */
function ctConvert(CartView $cart): void
{
    DB::table(app(TableNames::class)->table('carts'))
        ->where('id', $cart->id->value)
        ->update(['state' => 'converted', 'converted_at' => now()]);
}
