<?php

declare(strict_types=1);

use Commerz\Context\CommerceContext;
use Commerz\Identity\SellableRef;
use Commerz\Identity\SellerRef;
use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Ports\Sellables\Data\SellableSnapshot;
use Commerz\Ports\Sellables\Enums\SellableKind;
use Commerz\Sales\Carts\Data\AddCartLine;
use Commerz\Sales\Carts\Data\CreateCart;
use Commerz\Sales\Checkout\Contracts\OrderSplitterPort;
use Commerz\Sales\Checkout\Internal\SingleOrderSplitter;
use Commerz\Sales\Orders\Data\DraftLine;
use Commerz\Sales\Orders\Data\DraftOrder;
use Commerz\Testkit\Commerce;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/../Helpers.php';

uses(RefreshDatabase::class, SlsEnvironment::class);

beforeEach(function (): void {
    slsConfig(['checkout' => ['require_delivery' => false]]);
    $this->channel = slsChannel();
    $this->seller = SellerRef::of('vendor', 'acme');

    Commerce::fake()->sellables->add(new SellableSnapshot(
        SellableRef::fromString('fake:seller-item'),
        'SKU-SELLER-1',
        'Vendor Item',
        SellableKind::Physical,
        false,
        basePrice: Money::ofMinor(2500, Currency::of('RUB')),
        sellerRef: $this->seller,
    ));
});

it('propagates optional SellerRef from SellableSnapshot through cart and order lines', function (): void {
    $cart = slsCarts()->create(new CreateCart(CommerceContext::for($this->channel, Currency::of('RUB'), 'en-US')));
    $cart = slsCarts()->addLine(new AddCartLine($cart->id, SellableRef::fromString('fake:seller-item'), 2));

    expect($cart->lines)->toHaveCount(1)
        ->and($cart->lines[0]->sellerRef)->toEqual($this->seller);

    $order = slsPlace($cart);

    expect($order->lines)->toHaveCount(1)
        ->and($order->lines[0]->sellerRef)->toEqual($this->seller)
        ->and(app(OrderSplitterPort::class))->toBeInstanceOf(SingleOrderSplitter::class);
});

it('propagates optional SellerRef on draft order lines', function (): void {
    $draft = slsOrders()->draft(new DraftOrder(
        CommerceContext::for($this->channel, Currency::of('RUB'), 'en-US'),
        [new DraftLine(SellableRef::fromString('fake:seller-item'), 1, $this->seller)],
    ));

    expect($draft->lines)->toHaveCount(1)
        ->and($draft->lines[0]->sellerRef)->toEqual($this->seller);
});
