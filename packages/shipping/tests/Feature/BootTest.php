<?php

declare(strict_types=1);

use Commerz\Shipping\Configuration\ShippingOptions;
use Commerz\Shipping\ShippingServiceProvider;
use Packman\Doctor\Contracts\DoctorCheck;
use Packman\Doctor\Contracts\DoctorRegistryContract;
use Packman\Errors\Contracts\ProblemRegistry;
use Packman\Schema\Contracts\Migration\SchemaSets;

it('registers the shipping service provider, options, schema sets, problems, and doctor check', function (): void {
    expect(app()->getProviders(ShippingServiceProvider::class))->not->toBeEmpty()
        ->and(config('commerz-shipping'))->toBeArray()
        ->and(app(ShippingOptions::class))->toBeInstanceOf(ShippingOptions::class)
        ->and(app(SchemaSets::class)->all())->not->toBeEmpty()
        ->and(array_map(static fn (DoctorCheck $c): string => $c->key(), app(DoctorRegistryContract::class)->all()))->toContain('commerz.shipping.configuration')
        ->and(app(ProblemRegistry::class)->fromWire('shipping.method_not_found')->title)->toBe('Shipping Method Not Found');
})->group('boot');
