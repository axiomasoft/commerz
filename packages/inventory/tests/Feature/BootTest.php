<?php

declare(strict_types=1);

use Commerz\Inventory\InventoryServiceProvider;

it('registers the inventory service provider and config', function (): void {
    expect(app()->getProviders(InventoryServiceProvider::class))->not->toBeEmpty()
        ->and(config('commerz-inventory'))->toBeArray();
})->group('boot');
