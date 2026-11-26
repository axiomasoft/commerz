<?php

declare(strict_types=1);

use Commerz\Catalog\Facades\Catalog;
use Commerz\Catalog\Media\Contracts\Media;
use Commerz\Catalog\Media\Data\AssetAttachmentId;
use Commerz\Catalog\Media\Data\AttachAsset;
use Commerz\Catalog\Media\Data\DetachAsset;
use Commerz\Catalog\Media\Data\ListProductAssets;
use Commerz\Catalog\Media\Data\UpdateAssetAttachment;
use Commerz\Catalog\Media\Enums\AssetRole;
use Commerz\Catalog\Media\Exceptions\AssetAttachmentNotFound;
use Commerz\Catalog\Options\Data\AddOptionValues;
use Commerz\Catalog\Options\Data\DefineOption;
use Commerz\Catalog\Options\Data\OptionValueId;
use Commerz\Catalog\Options\Exceptions\UnknownOptionValue;
use Commerz\Catalog\Products\Data\CreateProduct;
use Commerz\Catalog\ProductTypes\Data\CreateProductType;
use Commerz\Measures\LocalizedText;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('exposes media as the bound singleton', function (): void {
    expect(Catalog::media())->toBe(app(Media::class));
});

it('attaches, updates, filters by role and option value, and detaches product assets', function (): void {
    $color = Catalog::options()->define(new DefineOption('color', LocalizedText::of('en', 'Color')));
    $color = Catalog::options()->addValues(new AddOptionValues($color->id, ['red' => LocalizedText::of('en', 'Red')]));
    $redId = $color->values[0]->id;

    $type = Catalog::productTypes()->create(new CreateProductType('shirt', LocalizedText::of('en', 'Shirt'), 'physical'));
    $tee = Catalog::products()->create(new CreateProduct($type->id, 'tee', LocalizedText::of('en', 'Tee')));

    $thumb = Catalog::media()->attach(new AttachAsset(
        product: $tee->id,
        assetId: 'asset-thumb',
        role: AssetRole::Thumbnail,
        alt: LocalizedText::of('en', 'Main thumbnail'),
        position: 0,
    ));

    $swatch = Catalog::media()->attach(new AttachAsset(
        product: $tee->id,
        assetId: 'asset-red-swatch',
        role: AssetRole::Swatch,
        alt: LocalizedText::of('en', 'Red swatch'),
        position: 1,
        optionValue: $redId,
    ));

    expect($thumb->role)->toBe(AssetRole::Thumbnail)
        ->and($thumb->alt?->texts)->toBe(['en' => 'Main thumbnail'])
        ->and($swatch->optionValue?->equals($redId))->toBeTrue();

    expect(Catalog::media()->ofProduct(new ListProductAssets($tee->id)))->toHaveCount(2)
        ->and(Catalog::media()->ofProduct(new ListProductAssets($tee->id, role: AssetRole::Swatch)))->toHaveCount(1)
        ->and(Catalog::media()->ofProduct(new ListProductAssets($tee->id, optionValue: $redId)))->toHaveCount(1);

    $updated = Catalog::media()->update(new UpdateAssetAttachment(
        attachment: $swatch->id,
        role: AssetRole::Gallery,
        clearAlt: true,
        clearOptionValue: true,
        position: 5,
    ));

    expect($updated->role)->toBe(AssetRole::Gallery)
        ->and($updated->alt)->toBeNull()
        ->and($updated->optionValue)->toBeNull()
        ->and($updated->position)->toBe(5);

    expect(fn () => Catalog::media()->attach(new AttachAsset($tee->id, 'x', AssetRole::Gallery, optionValue: OptionValueId::generate())))
        ->toThrow(UnknownOptionValue::class);

    Catalog::media()->detach(new DetachAsset($thumb->id));
    expect(fn () => Catalog::media()->find($thumb->id))->toThrow(AssetAttachmentNotFound::class)
        ->and(fn () => Catalog::media()->find(AssetAttachmentId::generate()))->toThrow(AssetAttachmentNotFound::class);
});
