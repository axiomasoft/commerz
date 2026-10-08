<?php

declare(strict_types=1);

use Commerz\Pricing\PricingServiceProvider;

it('registers the pricing service provider and config', function (): void {
    expect(app()->getProviders(PricingServiceProvider::class))->not->toBeEmpty()
        ->and(config('commerz-pricing'))->toBeArray();
})->group('boot');
