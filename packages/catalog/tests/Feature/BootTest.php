<?php

declare(strict_types=1);

use Commerz\Catalog\CatalogServiceProvider;

it('registers the catalog service provider and config', function (): void {
    expect(app()->getProviders(CatalogServiceProvider::class))->not->toBeEmpty()
        ->and(config('commerz-catalog'))->toBeArray();
})->group('boot');
