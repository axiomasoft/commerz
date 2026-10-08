<?php

declare(strict_types=1);

use Commerz\Shipping\ShippingServiceProvider;

it('registers the shipping service provider and config', function (): void {
    expect(app()->getProviders(ShippingServiceProvider::class))->not->toBeEmpty()
        ->and(config('commerz-shipping'))->toBeArray();
})->group('boot');
