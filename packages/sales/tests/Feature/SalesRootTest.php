<?php

declare(strict_types=1);

use Commerz\Errors\InvalidConfiguration;
use Commerz\Sales\Carts\Contracts\Carts;
use Commerz\Sales\Checkout\Contracts\Checkout;
use Commerz\Sales\Facades\Sales as SalesFacade;
use Commerz\Sales\Orders\Contracts\Orders;
use Commerz\Sales\Sales;

it('hands out the module contracts from the root', function (): void {
    config(['commerz.stock' => 'unlimited']);
    $root = app(Sales::class);

    expect($root->carts())->toBe(app(Carts::class))
        ->and($root->checkout())->toBe(app(Checkout::class))
        ->and($root->orders())->toBe(app(Orders::class))
        ->and(app(Sales::class))->toBe($root);
});

it('reaches the same modules through the facade', function (): void {
    config(['commerz.stock' => 'unlimited']);

    expect(SalesFacade::carts())->toBe(app(Carts::class))
        ->and(SalesFacade::checkout())->toBe(app(Checkout::class))
        ->and(SalesFacade::orders())->toBe(app(Orders::class));
});

it('resolves a module only when it is asked for, so the carts need no stock port', function (): void {
    $root = app(Sales::class);

    expect($root->carts())->toBeInstanceOf(Carts::class)
        ->and(fn () => $root->checkout())->toThrow(InvalidConfiguration::class, 'commerz.stock');
});
