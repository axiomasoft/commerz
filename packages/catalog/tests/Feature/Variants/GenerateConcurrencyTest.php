<?php

declare(strict_types=1);

use Commerz\Catalog\Facades\Catalog;
use Commerz\Catalog\Options\Data\DefineOption;
use Commerz\Catalog\Products\Data\CreateProduct;
use Commerz\Catalog\ProductTypes\Data\CreateProductType;
use Commerz\Catalog\Variants\Data\GenerateVariants;
use Commerz\Measures\LocalizedText;
use Commerz\Testkit\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Assert;

// Committed data across processes: no transactional isolation; the catalog tables are emptied after each test.

beforeEach(function (): void {
    if (! function_exists('pcntl_fork')) {
        Assert::markTestSkipped('pcntl is required.');
    }

    $pending = $this->artisan('migrate');
    if (! $pending instanceof PendingCommand) {
        Assert::fail('Expected a pending Artisan command.');
    }

    $pending->assertSuccessful();
});

afterEach(function (): void {
    DB::statement('truncate cmz_attribute_index, cmz_variant_option_values, cmz_variants, cmz_product_options, cmz_products,'
        .' cmz_product_type_attributes, cmz_product_type_options, cmz_product_types, cmz_option_values, cmz_options,'
        .' cmz_attribute_choices, cmz_attributes cascade');
});

it('creates each combination exactly once when processes generate the same axes (CAT-01)', function (): void {
    $label = LocalizedText::of('en', 'X');
    Catalog::options()->define(new DefineOption('color', $label, ['red' => $label, 'blue' => $label, 'green' => $label]));
    Catalog::options()->define(new DefineOption('size', $label, ['s' => $label, 'm' => $label, 'l' => $label]));
    $type = Catalog::productTypes()->create(new CreateProductType('apparel', $label, variantAxes: ['color', 'size']));
    $product = Catalog::products()->create(new CreateProduct($type->id, 'basic-tee', $label));
    $command = new GenerateVariants($product->id, ['color' => ['red', 'blue', 'green'], 'size' => ['s', 'm', 'l']], 'TEE-{color}-{size}');

    $outcomes = Concurrency::run(4, function () use ($command): array {
        $result = Catalog::variants()->generate($command);

        return [count($result->created), count($result->skipped)];
    });

    expect(DB::table('cmz_variants')->where('product_id', $product->id->value)->count())->toBe(9)
        ->and(DB::table('cmz_variants')->distinct()->count('option_signature'))->toBe(9)
        ->and(DB::table('cmz_variant_option_values')->count())->toBe(18)
        ->and(array_map(fn (array $outcome): int => $outcome[0] + $outcome[1], $outcomes))->toBe([9, 9, 9, 9])
        ->and(array_sum(array_column($outcomes, 0)))->toBe(9);
})->group('concurrency');
