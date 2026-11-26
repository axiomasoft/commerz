<?php

declare(strict_types=1);

use Commerz\Catalog\Brands\Data\BrandId;
use Commerz\Catalog\Brands\Data\CreateBrand;
use Commerz\Catalog\Brands\Data\ListBrands;
use Commerz\Catalog\Brands\Data\UpdateBrand;
use Commerz\Errors\InvalidCommand;
use Commerz\Measures\LocalizedText;

it('validates CreateBrand and UpdateBrand inputs', function (): void {
    $brand = new CreateBrand(
        code: 'acme-corp',
        name: LocalizedText::of('en', 'Acme Corp'),
        description: LocalizedText::of('en', 'Maker of fine goods'),
        logoAssetId: 'asset-logo-1',
        websiteUrl: 'https://acme.example.com',
    );

    expect($brand->code)->toBe('acme-corp')
        ->and($brand->logoAssetId)->toBe('asset-logo-1')
        ->and($brand->websiteUrl)->toBe('https://acme.example.com');

    expect(fn () => new CreateBrand('Bad Code', LocalizedText::of('en', 'X')))->toThrow(InvalidCommand::class)
        ->and(fn () => new CreateBrand('acme', LocalizedText::of('en', 'X'), logoAssetId: '   '))->toThrow(InvalidCommand::class)
        ->and(fn () => new CreateBrand('acme', LocalizedText::of('en', 'X'), websiteUrl: 'not-a-url'))->toThrow(InvalidCommand::class)
        ->and(fn () => new UpdateBrand(BrandId::generate(), logoAssetId: ''))->toThrow(InvalidCommand::class)
        ->and(fn () => new UpdateBrand(BrandId::generate(), websiteUrl: 'ftp//bad'))->toThrow(InvalidCommand::class)
        ->and(fn () => new ListBrands(first: 0))->toThrow(InvalidCommand::class)
        ->and(fn () => new ListBrands(first: 201))->toThrow(InvalidCommand::class);
});
