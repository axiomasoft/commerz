<?php

declare(strict_types=1);

use Commerz\Catalog\Configuration\CatalogOptions;
use Packman\Config\Exceptions\InvalidConfiguration;

it('defaults and reads max_generated_variants', function (): void {
    expect(CatalogOptions::fromArray([])->maxGeneratedVariants)->toBe(100)
        ->and(CatalogOptions::fromArray(['max_generated_variants' => 1])->maxGeneratedVariants)->toBe(1)
        ->and(CatalogOptions::fromArray(['max_generated_variants' => 10000])->maxGeneratedVariants)->toBe(10000)
        ->and(CatalogOptions::fromArray(require __DIR__.'/../../config/commerz-catalog.php'))->toEqual(CatalogOptions::fromArray([]));
});

it('rejects unknown keys under closed schema validation', function (): void {
    expect(fn () => CatalogOptions::fromArray(['unknown_key' => 1], 'app.catalog'))
        ->toThrow(InvalidConfiguration::class, 'app.catalog.unknown_key');
});

it('rejects values outside 1..10000 with the key path', function (mixed $value): void {
    expect(fn () => CatalogOptions::fromArray(['max_generated_variants' => $value]))
        ->toThrow(InvalidConfiguration::class, 'commerz-catalog.max_generated_variants');
})->with([0, -1, 10001, '100', 1.5, true]);
