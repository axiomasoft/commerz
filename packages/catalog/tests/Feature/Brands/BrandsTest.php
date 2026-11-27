<?php

declare(strict_types=1);

use Commerz\Catalog\Brands\Contracts\Brands;
use Commerz\Catalog\Brands\Data\ArchiveBrand;
use Commerz\Catalog\Brands\Data\BrandId;
use Commerz\Catalog\Brands\Data\CreateBrand;
use Commerz\Catalog\Brands\Data\ListBrands;
use Commerz\Catalog\Brands\Data\UpdateBrand;
use Commerz\Catalog\Brands\Enums\BrandStatus;
use Commerz\Catalog\Brands\Exceptions\BrandArchived;
use Commerz\Catalog\Brands\Exceptions\BrandNotFound;
use Commerz\Catalog\Common\Exceptions\DuplicateCode;
use Commerz\Catalog\Facades\Catalog;
use Commerz\Measures\LocalizedText;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('exposes brands as the bound singleton', function (): void {
    expect(Catalog::brands())->toBe(app(Brands::class));
});

it('creates, finds, updates, archives, and cursor-lists brands', function (): void {
    $acme = Catalog::brands()->create(new CreateBrand(
        code: 'acme',
        name: LocalizedText::of('en', 'Acme'),
        description: LocalizedText::of('en', 'Original description'),
        logoAssetId: 'logo-1',
        websiteUrl: 'https://acme.example.com',
    ));

    expect($acme->code)->toBe('acme')
        ->and($acme->status)->toBe(BrandStatus::Active)
        ->and($acme->logoAssetId)->toBe('logo-1')
        ->and(Catalog::brands()->findByCode('acme')->id->equals($acme->id))->toBeTrue()
        ->and(fn () => Catalog::brands()->create(new CreateBrand('acme', LocalizedText::of('en', 'Dup'))))->toThrow(DuplicateCode::class)
        ->and(fn () => Catalog::brands()->find(BrandId::generate()))->toThrow(BrandNotFound::class);

    $updated = Catalog::brands()->update(new UpdateBrand(
        brand: $acme->id,
        name: LocalizedText::of('en', 'Acme Corp'),
        clearDescription: true,
        clearLogoAssetId: true,
        clearWebsiteUrl: true,
    ));

    expect($updated->name->texts)->toBe(['en' => 'Acme Corp'])
        ->and($updated->description)->toBeNull()
        ->and($updated->logoAssetId)->toBeNull()
        ->and($updated->websiteUrl)->toBeNull();

    $beta = Catalog::brands()->create(new CreateBrand('beta', LocalizedText::of('en', 'Beta')));
    $gamma = Catalog::brands()->create(new CreateBrand('gamma', LocalizedText::of('en', 'Gamma')));

    $page1 = Catalog::brands()->list(new ListBrands(first: 2));
    expect(array_map(static fn (object $b): string => $b->code, $page1->items))->toBe(['acme', 'beta'])
        ->and($page1->next)->not->toBeNull();

    $page2 = Catalog::brands()->list(new ListBrands(first: 2, after: $page1->next));
    expect(array_map(static fn (object $b): string => $b->code, $page2->items))->toBe(['gamma'])
        ->and($page2->next)->toBeNull();

    $archived = Catalog::brands()->archive(new ArchiveBrand($beta->id));
    expect($archived->status)->toBe(BrandStatus::Archived)
        ->and(fn () => Catalog::brands()->update(new UpdateBrand($beta->id, name: LocalizedText::of('en', 'X'))))->toThrow(BrandArchived::class);

    $activeOnly = Catalog::brands()->list(new ListBrands(includeArchived: false));
    expect(array_map(static fn (object $b): string => $b->code, $activeOnly->items))->toBe(['acme', 'gamma']);

    $withArchived = Catalog::brands()->list(new ListBrands(includeArchived: true));
    expect(array_map(static fn (object $b): string => $b->code, $withArchived->items))->toBe(['acme', 'beta', 'gamma']);
});
