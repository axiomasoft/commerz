<?php

declare(strict_types=1);

use Commerz\Catalog\Common\Internal\CatalogConfig;
use Commerz\Errors\InvalidConfiguration;

it('defaults and reads max_generated_variants', function (): void {
    expect(CatalogConfig::fromArray([])->maxGeneratedVariants)->toBe(100)
        ->and(CatalogConfig::fromArray(['max_generated_variants' => 1])->maxGeneratedVariants)->toBe(1)
        ->and(CatalogConfig::fromArray(['max_generated_variants' => 10000])->maxGeneratedVariants)->toBe(10000);
});

it('rejects values outside 1..10000 with the key path', function (mixed $value): void {
    expect(fn () => CatalogConfig::fromArray(['max_generated_variants' => $value]))
        ->toThrow(function (InvalidConfiguration $e): void {
            expect($e->path)->toBe('commerz-catalog.max_generated_variants');
        });
})->with([0, -1, 10001, '100', 1.5, true]);
