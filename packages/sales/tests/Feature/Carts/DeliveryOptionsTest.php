<?php

declare(strict_types=1);

use Commerz\Money\Money;
use Commerz\Ports\Delivery\Data\DeliveryOption;
use Commerz\Ports\Sellables\Enums\SellableKind;
use Commerz\Sales\Carts\Data\CartView;
use Commerz\Sales\Carts\Data\SetCartAddress;
use Commerz\Sales\Carts\Enums\AddressRole;
use Commerz\Sales\Carts\Exceptions\ShippingAddressRequired;
use Commerz\Testkit\Commerce;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/Helpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->commerce = Commerce::fake();
    $this->commerce->sellables->add(
        ctSnapshot('sku:heavy', '100.00', grams: 500),
        ctSnapshot('sku:light', '40.00', grams: 100),
        ctSnapshot('sku:nogram', '10.00'),
        ctSnapshot('svc:install', '300.00', SellableKind::Service, grams: 9999),
    );
    $this->commerce->delivery->offer(new DeliveryOption('courier', 'Courier', Money::of('250.00', 'RUB'), 'cdek', 2));
    $this->web = ctChannel();
});

function doCart(array $lines, bool $address = true): CartView
{
    $cart = ctCart(test()->web);

    foreach ($lines as $ref => $quantity) {
        $cart = ctAdd($cart, $ref, $quantity);
    }

    if ($address) {
        $cart = ctCarts()->setAddress(new SetCartAddress($cart->id, AddressRole::Shipping, ctAddress()));
    }

    return $cart;
}

it('asks the port for the goods that ship: weight is the sum of weight × quantity', function (): void {
    $cart = doCart(['sku:heavy' => 2, 'sku:light' => 3, 'sku:nogram' => 1, 'svc:install' => 1]);

    $options = ctCarts()->deliveryOptions($cart->id);
    $request = $this->commerce->delivery->requests[0];

    expect($options)->toHaveCount(1)
        ->and($options[0]->methodCode)->toBe('courier')
        ->and($request->parcel->weightGrams)->toBe(2 * 500 + 3 * 100)
        ->and($request->parcel->value->toDecimal())->toBe('330.00')
        ->and(array_map(strval(...), $request->sellables))->toBe(['sku:heavy', 'sku:light', 'sku:nogram'])
        ->and($request->destination)->toEqual(ctAddress())
        ->and($request->context->channel)->toEqual($this->web);
});

it('declares the value of the goods that ship, not of the services', function (): void {
    $cart = doCart(['sku:heavy' => 1, 'svc:install' => 1]);

    ctCarts()->deliveryOptions($cart->id);

    expect($this->commerce->delivery->requests[0]->parcel->value->toDecimal())->toBe('100.00');
});

it('needs the shipping address', function (): void {
    $cart = doCart(['sku:heavy' => 1], address: false);

    expect(fn () => ctCarts()->deliveryOptions($cart->id))->toThrow(ShippingAddressRequired::class)
        ->and($this->commerce->delivery->requests)->toBe([]);
});

it('offers nothing when no line ships', function (): void {
    $services = doCart(['svc:install' => 1]);
    $empty = doCart([]);

    expect(ctCarts()->deliveryOptions($services->id))->toBe([])
        ->and(ctCarts()->deliveryOptions($empty->id))->toBe([])
        ->and($this->commerce->delivery->requests)->toBe([]);
});

it('offers only what the port offers in the currency of the cart', function (): void {
    $cart = doCart(['sku:heavy' => 1]);
    $this->commerce->delivery->offer(new DeliveryOption('abroad', 'Abroad', Money::of('10.00', 'USD'), 'dhl'));

    expect(array_map(fn ($option) => $option->methodCode, ctCarts()->deliveryOptions($cart->id)))->toBe(['courier']);
});
