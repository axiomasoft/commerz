<?php

declare(strict_types=1);

use Commerz\Catalog\Attributes\Data\DefineAttribute;
use Commerz\Catalog\Attributes\Data\UpdateAttribute;
use Commerz\Catalog\Facades\Catalog;
use Commerz\Catalog\Products\Data\CreateProduct;
use Commerz\Catalog\Products\Data\SetProductAttributes;
use Commerz\Catalog\ProductTypes\Data\CreateProductType;
use Commerz\Measures\LocalizedText;
use Commerz\Testkit\Concurrency;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->artisan('migrate')->assertSuccessful();
});

afterEach(function (): void {
    DB::statement('truncate cmz_products, cmz_product_types, cmz_attributes cascade');
});

it('keeps the projection consistent while attribute writes race reindexing (CAT-02)', function (): void {
    $label = LocalizedText::of('en', 'Projection');
    $attribute = Catalog::attributes()->define(new DefineAttribute('count', 'integer', $label, filterable: true));
    $type = Catalog::productTypes()->create(new CreateProductType('projection', $label, productAttributes: ['count']));
    $product = Catalog::products()->create(new CreateProduct($type->id, 'projection', $label, attributes: ['count' => 0]));

    $outcomes = Concurrency::run(2, function (int $worker) use ($attribute, $product): bool {
        foreach (range(1, 20) as $n) {
            if ($worker === 0) {
                Catalog::products()->setAttributes(new SetProductAttributes($product->id, ['count' => $n]));
            } else {
                Catalog::attributes()->update(new UpdateAttribute($attribute->id, filterable: $n % 2 === 0));
            }
        }

        return true;
    });

    expect($outcomes)->toBe([true, true])
        ->and(Catalog::products()->find($product->id)->attributes)->toBe(['count' => 20])
        ->and(DB::table('cmz_attribute_index')->where('product_id', $product->id->value)->count())->toBe(1)
        ->and(DB::table('cmz_attribute_index')->where('product_id', $product->id->value)->value('num'))->toBe('20');
})->group('concurrency');
