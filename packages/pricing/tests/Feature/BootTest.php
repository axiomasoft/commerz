<?php

declare(strict_types=1);

use Commerz\Pricing\Configuration\PricingOptions;
use Commerz\Pricing\PricingServiceProvider;
use Packman\Doctor\Contracts\DoctorCheck;
use Packman\Doctor\Contracts\DoctorRegistryContract;
use Packman\Errors\Contracts\ProblemRegistry;
use Packman\Schema\Contracts\Migration\SchemaSets;

it('registers the pricing service provider, options, schema sets, problems, and doctor check', function (): void {
    expect(app()->getProviders(PricingServiceProvider::class))->not->toBeEmpty()
        ->and(config('commerz-pricing'))->toBeArray()
        ->and(app(PricingOptions::class))->toBeInstanceOf(PricingOptions::class)
        ->and(app(SchemaSets::class)->all())->not->toBeEmpty()
        ->and(array_map(static fn (DoctorCheck $c): string => $c->key(), app(DoctorRegistryContract::class)->all()))->toContain('commerz.pricing.configuration')
        ->and(app(ProblemRegistry::class)->fromWire('pricing.price_list_not_found')->title)->toBe('Price List Not Found');
})->group('boot');
