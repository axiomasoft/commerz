<?php

declare(strict_types=1);

use Commerz\Catalog\Attributes\Data\DefineAttribute;
use Commerz\Catalog\Attributes\Exceptions\AttributeNotAllowed;
use Commerz\Catalog\Attributes\Exceptions\InvalidAttributeValue;
use Commerz\Catalog\Attributes\Exceptions\MissingRequiredAttributes;
use Commerz\Catalog\Common\Exceptions\DuplicateCode;
use Commerz\Catalog\Facades\Catalog;
use Commerz\Catalog\Options\Data\DefineOption;
use Commerz\Catalog\Products\Contracts\Products;
use Commerz\Catalog\Products\Data\ArchiveProduct;
use Commerz\Catalog\Products\Data\CreateProduct;
use Commerz\Catalog\Products\Data\ListProducts;
use Commerz\Catalog\Products\Data\ProductId;
use Commerz\Catalog\Products\Data\ProductView;
use Commerz\Catalog\Products\Data\SetProductAttributes;
use Commerz\Catalog\Products\Data\UpdateProduct;
use Commerz\Catalog\Products\Enums\ProductStatus;
use Commerz\Catalog\Products\Events\ProductCreated;
use Commerz\Catalog\Products\Events\ProductUpdated;
use Commerz\Catalog\Products\Exceptions\ProductArchived;
use Commerz\Catalog\Products\Exceptions\ProductNotFound;
use Commerz\Catalog\ProductTypes\Data\ArchiveProductType;
use Commerz\Catalog\ProductTypes\Data\CreateProductType;
use Commerz\Catalog\ProductTypes\Data\ProductTypeId;
use Commerz\Catalog\ProductTypes\Data\ProductTypeView;
use Commerz\Catalog\ProductTypes\Data\UpdateProductType;
use Commerz\Catalog\ProductTypes\Exceptions\ProductTypeArchived;
use Commerz\Catalog\ProductTypes\Exceptions\ProductTypeNotFound;
use Commerz\Errors\InvalidCommand;
use Commerz\Measures\LocalizedText;
use Commerz\Measures\Measure;
use Commerz\Pagination\Page;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

function prdLabel(string $text): LocalizedText
{
    return LocalizedText::of('en', $text);
}

/** An `apparel` type: required enum `material`, optional measure `density` and text `note`, variant text `fit`, axes size and color. */
function prdApparel(): ProductTypeView
{
    Catalog::attributes()->define(new DefineAttribute('material', 'enum', prdLabel('Material'), choices: [
        'cotton' => prdLabel('Cotton'), 'linen' => prdLabel('Linen'),
    ], filterable: true));
    Catalog::attributes()->define(new DefineAttribute('density', 'measure', prdLabel('Density'), ['unit' => 'gsm'], filterable: true));
    Catalog::attributes()->define(new DefineAttribute('note', 'text', prdLabel('Note')));
    Catalog::attributes()->define(new DefineAttribute('fit', 'text', prdLabel('Fit')));
    Catalog::options()->define(new DefineOption('color', prdLabel('Color'), ['red' => prdLabel('Red')]));
    Catalog::options()->define(new DefineOption('size', prdLabel('Size'), ['m' => prdLabel('M')]));

    return Catalog::productTypes()->create(new CreateProductType(
        'apparel',
        prdLabel('Apparel'),
        productAttributes: ['material', 'density', 'note'],
        variantAttributes: ['fit'],
        variantAxes: ['size', 'color'],
        required: ['material'],
    ));
}

/** @param  array<string, mixed>  $attributes */
function prdTee(ProductTypeId $type, string $handle = 'basic-tee', array $attributes = ['material' => 'cotton']): ProductView
{
    return Catalog::products()->create(new CreateProduct($type, $handle, prdLabel('Tee'), attributes: $attributes));
}

it('exposes products as the bound singleton', function (): void {
    expect(Catalog::products())->toBe(app(Products::class));
});

it('creates a product with canonical attributes and a snapshot of the type axes', function (): void {
    $type = prdApparel();

    $product = Catalog::products()->create(new CreateProduct(
        $type->id,
        'basic-tee',
        prdLabel('Tee'),
        prdLabel('Soft'),
        ['material' => 'cotton', 'density' => Measure::of('180', 'gsm')],
        ProductStatus::Draft,
    ));

    expect($product->type->equals($type->id))->toBeTrue()
        ->and($product->handle)->toBe('basic-tee')
        ->and($product->name->texts)->toBe(['en' => 'Tee'])
        ->and($product->description?->texts)->toBe(['en' => 'Soft'])
        ->and($product->status)->toBe(ProductStatus::Draft)
        ->and($product->attributes)->toEqual(['material' => 'cotton', 'density' => ['unit' => 'gsm', 'value' => '180.000']])
        ->and($product->axes)->toBe(['size', 'color'])
        ->and(Catalog::products()->findByHandle('basic-tee')->id->equals($product->id))->toBeTrue();

    Catalog::options()->define(new DefineOption('style', prdLabel('Style'), ['a' => prdLabel('A')]));
    Catalog::attributes()->define(new DefineAttribute('origin', 'text', prdLabel('Origin')));
    Catalog::productTypes()->update(new UpdateProductType($type->id, addProductAttributes: ['origin']));

    expect(Catalog::products()->find($product->id)->axes)->toBe(['size', 'color']);
});

it('refuses missing or archived types, duplicate handles and malformed commands', function (): void {
    $type = prdApparel();
    prdTee($type->id);

    expect(fn () => prdTee(ProductTypeId::generate(), 'other'))->toThrow(ProductTypeNotFound::class)
        ->and(fn () => prdTee($type->id))->toThrow(DuplicateCode::class)
        ->and(fn () => new CreateProduct($type->id, 'Bad Handle', prdLabel('X')))->toThrow(InvalidCommand::class)
        ->and(fn () => new CreateProduct($type->id, 'x', prdLabel('X'), status: ProductStatus::Archived))->toThrow(InvalidCommand::class)
        ->and(fn () => new CreateProduct($type->id, 'x', prdLabel('X'), attributes: ['Bad Code' => 1]))->toThrow(InvalidCommand::class)
        ->and(fn () => new UpdateProduct(ProductId::generate(), status: ProductStatus::Archived))->toThrow(InvalidCommand::class)
        ->and(fn () => new SetProductAttributes(ProductId::generate()))->toThrow(InvalidCommand::class);

    Catalog::productTypes()->archive(new ArchiveProductType($type->id));

    expect(fn () => prdTee($type->id, 'late'))->toThrow(ProductTypeArchived::class);
});

it(
    'rolls the whole creation back when an attribute is wrong',
    /**
     * @param  array<string, mixed>  $attributes
     */
    function (array $attributes, string $error): void {
        $type = prdApparel();
        $typed = [];
        foreach ($attributes as $key => $value) {
            if (! is_string($key)) {
                throw new RuntimeException('Attribute codes must be strings.');
            }
            $typed[$key] = $value;
        }

        expect(fn () => prdTee($type->id, 'tee', $typed))->toThrow($error);
        expect(DB::table('cmz_products')->count())->toBe(0)
            ->and(DB::table('cmz_product_options')->count())->toBe(0)
            ->and(DB::table('cmz_attribute_index')->count())->toBe(0);
    })->with([
        'required missing' => [['note' => 'x'], MissingRequiredAttributes::class],
        'variant level on the product' => [['material' => 'cotton', 'fit' => 'slim'], AttributeNotAllowed::class],
        'unknown to the type' => [['material' => 'cotton', 'ghost' => 'x'], AttributeNotAllowed::class],
        'invalid value' => [['material' => 'silk'], InvalidAttributeValue::class],
        'null value' => [['material' => null], InvalidAttributeValue::class],
    ]);

it('updates fields, moves between draft and active and refuses a taken handle', function (): void {
    $type = prdApparel();
    $product = prdTee($type->id);
    prdTee($type->id, 'other');

    $updated = Catalog::products()->update(new UpdateProduct(
        $product->id,
        handle: 'better-tee',
        name: prdLabel('Better'),
        description: prdLabel('New'),
        status: ProductStatus::Draft,
    ));

    expect($updated->handle)->toBe('better-tee')
        ->and($updated->name->texts)->toBe(['en' => 'Better'])
        ->and($updated->description?->texts)->toBe(['en' => 'New'])
        ->and($updated->status)->toBe(ProductStatus::Draft)
        ->and($updated->attributes)->toBe(['material' => 'cotton'])
        ->and(Catalog::products()->update(new UpdateProduct($product->id, status: ProductStatus::Active))->status)->toBe(ProductStatus::Active)
        ->and(fn () => Catalog::products()->update(new UpdateProduct($product->id, handle: 'other')))->toThrow(DuplicateCode::class)
        ->and(Catalog::products()->find($product->id)->handle)->toBe('better-tee');
});

it('sets and removes attributes but never a required one', function (): void {
    $type = prdApparel();
    $product = prdTee($type->id);

    $set = Catalog::products()->setAttributes(new SetProductAttributes($product->id, ['note' => 'hello', 'material' => 'linen']));

    expect($set->attributes)->toBe(['material' => 'linen', 'note' => 'hello']);

    $removed = Catalog::products()->setAttributes(new SetProductAttributes($product->id, remove: ['note']));

    expect($removed->attributes)->toBe(['material' => 'linen'])
        ->and(fn () => Catalog::products()->setAttributes(new SetProductAttributes($product->id, remove: ['material'])))
        ->toThrow(MissingRequiredAttributes::class)
        ->and(fn () => Catalog::products()->setAttributes(new SetProductAttributes($product->id, ['note' => 'x'], ['note'])))
        ->toThrow(InvalidCommand::class)
        ->and(fn () => Catalog::products()->setAttributes(new SetProductAttributes($product->id, ['fit' => 'slim'])))
        ->toThrow(AttributeNotAllowed::class)
        ->and(Catalog::products()->find($product->id)->attributes)->toBe(['material' => 'linen']);
});

it('archives idempotently and then refuses changes', function (): void {
    $type = prdApparel();
    $product = prdTee($type->id);

    $archived = Catalog::products()->archive(new ArchiveProduct($product->id));

    expect($archived->status)->toBe(ProductStatus::Archived)
        ->and(Catalog::products()->archive(new ArchiveProduct($product->id))->updatedAt)->toEqual($archived->updatedAt)
        ->and(fn () => Catalog::products()->update(new UpdateProduct($product->id, name: prdLabel('X'))))->toThrow(ProductArchived::class)
        ->and(fn () => Catalog::products()->setAttributes(new SetProductAttributes($product->id, ['note' => 'x'])))->toThrow(ProductArchived::class)
        ->and(fn () => Catalog::products()->archive(new ArchiveProduct(ProductId::generate())))->toThrow(ProductNotFound::class);
});

it('reports a missing product', function (): void {
    expect(fn () => Catalog::products()->find(ProductId::generate()))->toThrow(ProductNotFound::class)
        ->and(fn () => Catalog::products()->findByHandle('nope'))->toThrow(ProductNotFound::class)
        ->and(fn () => Catalog::products()->update(new UpdateProduct(ProductId::generate(), name: prdLabel('X'))))->toThrow(ProductNotFound::class);
});

it('lists by handle with a keyset cursor and type and status filters', function (): void {
    $type = prdApparel();
    $bare = Catalog::productTypes()->create(new CreateProductType('bare', prdLabel('Bare')));

    foreach (['d', 'b', 'a'] as $handle) {
        prdTee($type->id, $handle);
    }

    $c = Catalog::products()->create(new CreateProduct($bare->id, 'c', prdLabel('C'), status: ProductStatus::Draft));

    $first = Catalog::products()->list(new ListProducts(first: 2));
    $second = Catalog::products()->list(new ListProducts(first: 2, after: $first->next));
    $handles = static function (Page $page): array {
        $handles = [];
        foreach ($page->items as $product) {
            if (! $product instanceof ProductView) {
                throw new RuntimeException('Expected a product view.');
            }
            $handles[] = $product->handle;
        }

        return $handles;
    };

    expect($handles($first))->toBe(['a', 'b'])
        ->and($first->hasMore())->toBeTrue()
        ->and($handles($second))->toBe(['c', 'd'])
        ->and($second->hasMore())->toBeFalse()
        ->and($handles(Catalog::products()->list(new ListProducts(type: $bare->id))))->toBe(['c'])
        ->and($handles(Catalog::products()->list(new ListProducts(status: ProductStatus::Active))))->toBe(['a', 'b', 'd'])
        ->and($c->axes)->toBe([])
        ->and(fn () => new ListProducts(first: 201))->toThrow(InvalidCommand::class);
});

it('dispatches product events only after commit', function (): void {
    $type = prdApparel();
    Event::fake([ProductCreated::class, ProductUpdated::class]);

    DB::transaction(function () use ($type): void {
        $product = prdTee($type->id);
        Catalog::products()->setAttributes(new SetProductAttributes($product->id, ['note' => 'x']));
        Catalog::products()->archive(new ArchiveProduct($product->id));

        Event::assertNotDispatched(ProductCreated::class);
        Event::assertNotDispatched(ProductUpdated::class);
    });

    Event::assertDispatched(ProductCreated::class, static fn (ProductCreated $e): bool => $e->product->handle === 'basic-tee');
    Event::assertDispatchedTimes(ProductUpdated::class, 2);
});

it('dispatches nothing for a change that changes nothing', function (): void {
    $type = prdApparel();
    $product = prdTee($type->id);
    Catalog::products()->archive(new ArchiveProduct($product->id));
    Event::fake([ProductUpdated::class]);

    Catalog::products()->archive(new ArchiveProduct($product->id));

    Event::assertNotDispatched(ProductUpdated::class);
});
