<?php

declare(strict_types=1);

use Commerz\Catalog\Facades\Catalog;
use Commerz\Catalog\Products\Data\ArchiveProduct;
use Commerz\Catalog\Products\Data\CreateProduct;
use Commerz\Catalog\Products\Data\ProductId;
use Commerz\Catalog\Products\Exceptions\ProductArchived;
use Commerz\Catalog\Products\Exceptions\ProductNotFound;
use Commerz\Catalog\ProductTypes\Data\CreateProductType;
use Commerz\Catalog\Relations\Contracts\ProductRelations;
use Commerz\Catalog\Relations\Data\AddProductRelation;
use Commerz\Catalog\Relations\Data\ListProductRelations;
use Commerz\Catalog\Relations\Data\ProductRelationView;
use Commerz\Catalog\Relations\Data\RemoveProductRelation;
use Commerz\Catalog\Relations\Data\ReplaceProductRelations;
use Commerz\Catalog\Relations\Enums\ProductRelationType;
use Commerz\Catalog\Relations\Exceptions\ProductRelationNotFound;
use Commerz\Measures\LocalizedText;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('exposes relations as the bound singleton', function (): void {
    expect(Catalog::relations())->toBe(app(ProductRelations::class));
});

it('adds, updates position, replaces, removes, and queries inverse product relations', function (): void {
    $type = Catalog::productTypes()->create(new CreateProductType('gear', LocalizedText::of('en', 'Gear'), 'physical'));
    $camera = Catalog::products()->create(new CreateProduct($type->id, 'camera', LocalizedText::of('en', 'Camera')));
    $lens = Catalog::products()->create(new CreateProduct($type->id, 'lens', LocalizedText::of('en', 'Lens')));
    $battery = Catalog::products()->create(new CreateProduct($type->id, 'battery', LocalizedText::of('en', 'Battery')));
    $case = Catalog::products()->create(new CreateProduct($type->id, 'case', LocalizedText::of('en', 'Case')));

    $added = Catalog::relations()->add(new AddProductRelation($camera->id, $lens->id, ProductRelationType::Accessory, 2));
    expect($added->type)->toBe(ProductRelationType::Accessory)->and($added->position)->toBe(2);

    $updated = Catalog::relations()->add(new AddProductRelation($camera->id, $lens->id, ProductRelationType::Accessory, 0));
    expect($updated->id->equals($added->id))->toBeTrue()->and($updated->position)->toBe(0);

    $replaced = Catalog::relations()->replace(new ReplaceProductRelations(
        $camera->id,
        ProductRelationType::CrossSell,
        [$case->id, $battery->id],
    ));
    expect(array_map(static fn (ProductRelationView $r): string => $r->targetProduct->value, $replaced))
        ->toBe([$case->id->value, $battery->id->value]);

    $allForCamera = Catalog::relations()->ofProduct(new ListProductRelations($camera->id));
    expect($allForCamera)->toHaveCount(3);

    $inverse = Catalog::relations()->inverseOf($battery->id, ProductRelationType::CrossSell);
    expect($inverse)->toHaveCount(1)
        ->and($inverse[0]->sourceProduct->equals($camera->id))->toBeTrue();

    Catalog::relations()->remove(new RemoveProductRelation($camera->id, $lens->id, ProductRelationType::Accessory));
    expect(fn () => Catalog::relations()->remove(new RemoveProductRelation($camera->id, $lens->id, ProductRelationType::Accessory)))
        ->toThrow(ProductRelationNotFound::class);

    Catalog::products()->archive(new ArchiveProduct($lens->id));
    expect(fn () => Catalog::relations()->add(new AddProductRelation($camera->id, $lens->id, ProductRelationType::Upsell)))
        ->toThrow(ProductArchived::class);
    expect(fn () => Catalog::relations()->add(new AddProductRelation($camera->id, ProductId::generate(), ProductRelationType::Upsell)))
        ->toThrow(ProductNotFound::class);
});
