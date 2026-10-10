<?php

declare(strict_types=1);

use Commerz\Sales\Carts\Contracts\Carts;
use Commerz\Sales\Facades\Sales as SalesFacade;
use Commerz\Sales\Sales;

it('hands out the module contracts from the root', function (): void {
    $root = app(Sales::class);

    expect($root->carts())->toBe(app(Carts::class))
        ->and(app(Sales::class))->toBe($root);
});

it('reaches the same modules through the facade', function (): void {
    expect(SalesFacade::carts())->toBe(app(Carts::class));
});
