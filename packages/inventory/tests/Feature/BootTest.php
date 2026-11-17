<?php

declare(strict_types=1);

use Commerz\Inventory\Configuration\InventoryOptions;
use Commerz\Inventory\InventoryServiceProvider;
use Packman\Doctor\Contracts\DoctorCheck;
use Packman\Doctor\Contracts\DoctorRegistryContract;
use Packman\Errors\Contracts\ProblemRegistry;
use Packman\Schema\Contracts\Migration\SchemaSets;

it('registers the inventory service provider, options, schema sets, problems, and doctor check', function (): void {
    expect(app()->getProviders(InventoryServiceProvider::class))->not->toBeEmpty()
        ->and(config('commerz-inventory'))->toBeArray()
        ->and(app(InventoryOptions::class))->toBeInstanceOf(InventoryOptions::class)
        ->and(app(SchemaSets::class)->all())->not->toBeEmpty()
        ->and(array_map(static fn (DoctorCheck $c): string => $c->key(), app(DoctorRegistryContract::class)->all()))->toContain('commerz.inventory.configuration')
        ->and(app(ProblemRegistry::class)->fromWire('inventory.location_not_found')->title)->toBe('Stock Location Not Found');
})->group('boot');
