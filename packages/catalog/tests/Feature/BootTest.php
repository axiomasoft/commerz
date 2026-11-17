<?php

declare(strict_types=1);

use Commerz\Catalog\CatalogServiceProvider;
use Commerz\Catalog\Configuration\CatalogOptions;
use Packman\Doctor\Contracts\DoctorCheck;
use Packman\Doctor\Contracts\DoctorRegistryContract;
use Packman\Errors\Contracts\ProblemRegistry;
use Packman\Schema\Contracts\Migration\SchemaSets;

it('registers the catalog service provider, options, schema sets, problems, and doctor check', function (): void {
    expect(app()->getProviders(CatalogServiceProvider::class))->not->toBeEmpty()
        ->and(config('commerz-catalog'))->toBeArray()
        ->and(app(CatalogOptions::class))->toBeInstanceOf(CatalogOptions::class)
        ->and(app(SchemaSets::class)->all())->not->toBeEmpty()
        ->and(array_map(static fn (DoctorCheck $c): string => $c->key(), app(DoctorRegistryContract::class)->all()))->toContain('commerz.catalog.configuration')
        ->and(app(ProblemRegistry::class)->fromWire('catalog.product_not_found')->title)->toBe('Product Not Found');
})->group('boot');
