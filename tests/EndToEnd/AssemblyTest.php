<?php

declare(strict_types=1);

use Commerz\Context\CommerceContext;
use Commerz\EndToEnd\Support\EndToEndTestCase;
use Commerz\EndToEnd\Support\PortMatrix;
use Commerz\EndToEnd\Support\ShopWorld;
use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Ports\Delivery\Data\Address;
use Commerz\Sales\Carts\Data\AddCartLine;
use Commerz\Sales\Carts\Data\CreateCart;
use Commerz\Sales\Carts\Data\SelectDelivery;
use Commerz\Sales\Carts\Data\SetCartAddress;
use Commerz\Sales\Carts\Enums\AddressRole;
use Commerz\Sales\Facades\Sales;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('binds every cross-package port to the real implementation', function (): void {
    expect(PortMatrix::resolved())->toBe(PortMatrix::IMPLEMENTATIONS)
        ->and(PortMatrix::sellableTypes())->toBe(['variant']);
});

it('binds the same ports and plugins whatever the order of the providers', function (): void {
    $this->bootInReverseOrder();

    expect(array_values(array_intersect(array_keys($this->app->getLoadedProviders()), EndToEndTestCase::allProviders())))->toBe(array_reverse(EndToEndTestCase::allProviders()))
        ->and(PortMatrix::resolved())->toBe(PortMatrix::IMPLEMENTATIONS)
        ->and(PortMatrix::sellableTypes())->toBe(['variant'])
        ->and(PortMatrix::stages())->toBe(PortMatrix::STAGES);
});

it('lists the seven plugins and passes the doctor', function (): void {
    expect(PortMatrix::plugins())->toBe(['commerz.catalog', 'commerz.core', 'commerz.inventory', 'commerz.pricing', 'commerz.promotions', 'commerz.sales', 'commerz.shipping']);

    $this->artisan('commerz:plugins')->assertSuccessful();
    $this->artisan('commerz:doctor')->assertSuccessful();
});

it('runs the quote stages of every package in the declared order', function (): void {
    expect(PortMatrix::stages())->toBe(PortMatrix::STAGES);
});

it('migrates the tables of every package on one connection', function (): void {
    $connection = DB::connection()->getName();

    foreach (['cmz_stores', 'cmz_products', 'cmz_variants', 'cmz_stock_items', 'cmz_price_lists', 'cmz_promotions', 'cmz_shipping_methods', 'cmz_orders'] as $table) {
        expect(Schema::connection($connection)->hasTable($table))->toBeTrue("missing {$table}");
    }
});

it('builds the shop through the public API and quotes a cart on the real stages', function (): void {
    $world = ShopWorld::build();
    $context = CommerceContext::for($world->channel, Currency::of('RUB'), 'ru');
    $cart = Sales::carts()->create(new CreateCart($context));
    Sales::carts()->addLine(new AddCartLine($cart->id, $world->small, 2));
    Sales::carts()->addLine(new AddCartLine($cart->id, $world->mug, 1));
    Sales::carts()->setAddress(new SetCartAddress($cart->id, AddressRole::Shipping, new Address('RU', 'RU-MOW', 'Moscow', '101000', ['Tverskaya 1'], 'Ivan', '+70000000000')));
    $options = Sales::carts()->deliveryOptions($cart->id);
    Sales::carts()->selectDelivery(new SelectDelivery($cart->id, ShopWorld::COURIER));
    $quote = Sales::carts()->quote($cart->id);

    // 2 × 1000.00 + 500.00 = 2500.00, −10% = 2250.00, + courier 300.00.
    expect($options[0]->methodCode)->toBe(ShopWorld::COURIER)
        ->and($options[0]->price)->toEqual(Money::of('300.00', 'RUB'))
        ->and($quote->delivery?->price)->toEqual($options[0]->price)
        ->and($quote->total)->toEqual(Money::of('2550.00', 'RUB'))
        ->and($quote->totals->taxTotal)->toEqual(Money::of('425.00', 'RUB'))
        ->and($quote->appliedPromotions())->toHaveCount(1)
        ->and($quote->orderAdjustments[0]->amount)->toEqual(Money::of('-250.00', 'RUB'));
});
