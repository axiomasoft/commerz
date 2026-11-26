<?php

declare(strict_types=1);

use Commerz\Sales\Carts\Contracts\Carts;
use Commerz\Sales\Checkout\Contracts\Checkout;
use Commerz\Sales\Facades\Sales as SalesFacade;
use Commerz\Sales\Fulfillments\Contracts\FulfillmentOrders;
use Commerz\Sales\Fulfillments\Contracts\Fulfillments;
use Commerz\Sales\OrderChanges\Contracts\OrderChanges;
use Commerz\Sales\Orders\Contracts\Orders;
use Commerz\Sales\Payments\Contracts\PaymentCollections;
use Commerz\Sales\Payments\Contracts\PaymentMethodResolver;
use Commerz\Sales\Payments\Contracts\Payments;
use Commerz\Sales\Returns\Contracts\Returns;
use Commerz\Sales\Sales;
use Packman\Config\Exceptions\InvalidConfiguration;

it('hands out the module contracts from the root', function (): void {
    config(['commerz.stock' => 'unlimited']);
    $root = app(Sales::class);

    expect($root->carts())->toBe(app(Carts::class))
        ->and($root->checkout())->toBe(app(Checkout::class))
        ->and($root->orders())->toBe(app(Orders::class))
        ->and($root->orderChanges())->toBe(app(OrderChanges::class))
        ->and($root->payments())->toBe(app(Payments::class))
        ->and($root->paymentCollections())->toBe(app(PaymentCollections::class))
        ->and($root->paymentMethods())->toBe(app(PaymentMethodResolver::class))
        ->and($root->fulfillments())->toBe(app(Fulfillments::class))
        ->and($root->fulfillmentOrders())->toBe(app(FulfillmentOrders::class))
        ->and($root->returns())->toBe(app(Returns::class))
        ->and(app(Sales::class))->toBe($root);
});

it('reaches the same modules through the facade', function (): void {
    config(['commerz.stock' => 'unlimited']);

    expect(SalesFacade::carts())->toBe(app(Carts::class))
        ->and(SalesFacade::checkout())->toBe(app(Checkout::class))
        ->and(SalesFacade::orders())->toBe(app(Orders::class))
        ->and(SalesFacade::orderChanges())->toBe(app(OrderChanges::class))
        ->and(SalesFacade::payments())->toBe(app(Payments::class))
        ->and(SalesFacade::paymentCollections())->toBe(app(PaymentCollections::class))
        ->and(SalesFacade::paymentMethods())->toBe(app(PaymentMethodResolver::class))
        ->and(SalesFacade::fulfillments())->toBe(app(Fulfillments::class))
        ->and(SalesFacade::fulfillmentOrders())->toBe(app(FulfillmentOrders::class))
        ->and(SalesFacade::returns())->toBe(app(Returns::class));
});

it('resolves a module only when it is asked for, so the carts need no stock port', function (): void {
    $root = app(Sales::class);

    expect($root->carts())->toBeInstanceOf(Carts::class)
        ->and(fn () => $root->checkout())->toThrow(InvalidConfiguration::class, 'commerz.stock');
});
