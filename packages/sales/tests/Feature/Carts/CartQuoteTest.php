<?php

declare(strict_types=1);

use Commerz\Identity\CustomerRef;
use Commerz\Identity\SellableRef;
use Commerz\Money\Money;
use Commerz\Ports\Sellables\Enums\SellableKind;
use Commerz\Quotes\Exceptions\QuoteFailed;
use Commerz\Sales\Carts\Data\EnterCode;
use Commerz\Sales\Carts\Data\RemoveCartLine;
use Commerz\Sales\Carts\Data\SelectDelivery;
use Commerz\Sales\Carts\Data\SetCartAddress;
use Commerz\Sales\Carts\Data\SetCartCustomer;
use Commerz\Sales\Carts\Enums\AddressRole;
use Commerz\Sales\Carts\Exceptions\ShippingAddressRequired;
use Commerz\Testkit\Commerce;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/Helpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->commerce = Commerce::fake();
    $this->commerce->sellables->add(ctSnapshot('sku:a', '100.00'), ctSnapshot('sku:b', '50.00'));
    $this->commerce->prices->set(SellableRef::fromString('sku:a'), Money::of('120.00', 'RUB'));
    $this->web = ctChannel();
});

it('quotes the lines with the prices of the price port, in the order of the cart', function (): void {
    $cart = ctAdd(ctAdd(ctCart($this->web), 'sku:b', 2), 'sku:a');

    $quote = ctCarts()->quote($cart->id);

    expect(array_map(fn ($line) => [$line->lineId, $line->quantity, $line->unitPrice->toDecimal(), $line->total->toDecimal()], $quote->lines))
        ->toBe([[$cart->lines[0]->id, 2, '50.00', '100.00'], [$cart->lines[1]->id, 1, '120.00', '120.00']])
        ->and($quote->total->toDecimal())->toBe('220.00')
        ->and($quote->context->channel)->toEqual($this->web)
        ->and($quote->context->currency->code)->toBe('RUB');
});

it('quotes an empty cart to zero', function (): void {
    $quote = ctCarts()->quote(ctCart($this->web)->id);

    expect($quote->lines)->toBe([])
        ->and($quote->total->toDecimal())->toBe('0.00');
});

it('prices in the context of the cart: the customer and the groups', function (): void {
    $cart = ctAdd(ctCart($this->web), 'sku:a');
    ctCarts()->setCustomer(new SetCartCustomer($cart->id, CustomerRef::of('crm', '3'), ['vip']));

    $quote = ctCarts()->quote($cart->id);

    expect($quote->context->customer)->toEqual(CustomerRef::of('crm', '3'))
        ->and($quote->context->customerGroups)->toBe(['vip'])
        ->and($this->commerce->prices->requests)->not->toBe([]);
});

it('hands the codes of the cart to the quote', function (): void {
    $cart = ctAdd(ctCart($this->web), 'sku:a');
    ctCarts()->enterCode(new EnterCode($cart->id, 'AUTUMN'));

    expect(ctCarts()->quote($cart->id)->codes)->toBe(['AUTUMN']);
});

it('follows the cart: a removed line is not quoted', function (): void {
    $cart = ctAdd(ctAdd(ctCart($this->web), 'sku:a'), 'sku:b');
    ctCarts()->removeLine(new RemoveCartLine($cart->id, $cart->lines[0]->id));

    expect(ctCarts()->quote($cart->id)->total->toDecimal())->toBe('50.00');
});

it('refuses to quote a selected delivery method without a shipping address', function (): void {
    $cart = ctAdd(ctCart($this->web), 'sku:a');
    ctCarts()->selectDelivery(new SelectDelivery($cart->id, 'courier'));

    expect(fn () => ctCarts()->quote($cart->id))->toThrow(ShippingAddressRequired::class);
});

it('fails the quote of a selected method when no package prices delivery', function (): void {
    $cart = ctAdd(ctCart($this->web), 'sku:a');
    ctCarts()->setAddress(new SetCartAddress($cart->id, AddressRole::Shipping, ctAddress()));
    ctCarts()->selectDelivery(new SelectDelivery($cart->id, 'courier'));

    expect(fn () => ctCarts()->quote($cart->id))->toThrow(QuoteFailed::class);
});

it('ignores a selected method when no line ships', function (): void {
    $this->commerce->sellables->add(ctSnapshot('svc:install', '300.00', SellableKind::Service));
    $cart = ctAdd(ctCart($this->web), 'svc:install');
    ctCarts()->setAddress(new SetCartAddress($cart->id, AddressRole::Shipping, ctAddress()));
    ctCarts()->selectDelivery(new SelectDelivery($cart->id, 'courier'));

    expect(ctCarts()->quote($cart->id)->total->toDecimal())->toBe('300.00');
});
