<?php

declare(strict_types=1);

use Commerz\Catalog\Attributes\Data\DefineAttribute;
use Commerz\Catalog\Facades\Catalog;
use Commerz\Catalog\Options\Data\DefineOption;
use Commerz\Catalog\Products\Data\AttributeFilter;
use Commerz\Catalog\Products\Data\UpsertProductItem;
use Commerz\Catalog\Products\Data\UpsertProducts;
use Commerz\Catalog\Products\Data\VariantFilter;
use Commerz\Catalog\ProductTypes\Data\CreateProductType;
use Commerz\Catalog\Search\Data\CatalogSearchQuery;
use Commerz\Catalog\Search\Enums\SearchSort;
use Commerz\Catalog\Variants\Data\UpsertVariantItem;
use Commerz\Catalog\Variants\Data\UpsertVariants;
use Commerz\Measures\LocalizedText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('runs disjunctive facets, keyset pagination, and 1,000+ SKU batch upserts on PostgreSQL 16+', function (): void {
    $label = static fn (string $t): LocalizedText => LocalizedText::of('en', $t);

    Catalog::options()->define(new DefineOption('color', $label('Color'), [
        'red' => $label('Red'), 'blue' => $label('Blue'), 'green' => $label('Green'), 'black' => $label('Black'), 'white' => $label('White'),
    ]));
    Catalog::options()->define(new DefineOption('size', $label('Size'), [
        'xs' => $label('XS'), 's' => $label('S'), 'm' => $label('M'), 'l' => $label('L'), 'xl' => $label('XL'),
    ]));
    Catalog::attributes()->define(new DefineAttribute('material', 'enum', $label('Material'), choices: [
        'cotton' => $label('Cotton'), 'linen' => $label('Linen'), 'wool' => $label('Wool'),
    ], filterable: true));
    Catalog::attributes()->define(new DefineAttribute('fit', 'enum', $label('Fit'), choices: [
        'slim' => $label('Slim'), 'regular' => $label('Regular'),
    ], filterable: true));

    $type = Catalog::productTypes()->create(new CreateProductType(
        'pg_apparel',
        $label('Apparel'),
        productAttributes: ['material'],
        variantAttributes: ['fit'],
        variantAxes: ['color', 'size'],
    ));

    // Upsert 40 products x 25 SKUs = 1,000 SKUs in bounded queries on PostgreSQL.
    $productItems = [];
    for ($i = 1; $i <= 40; $i++) {
        $productItems[] = new UpsertProductItem(
            $type->id,
            sprintf('pg-shirt-%03d', $i),
            $label("PG Shirt {$i}"),
            attributes: ['material' => ['cotton', 'linen', 'wool'][$i % 3]],
        );
    }

    $productsResult = Catalog::products()->upsertMany(new UpsertProducts($productItems));
    expect($productsResult->createdCount)->toBe(40)
        ->and($productsResult->outboxEventCount)->toBe(1);

    $colors = ['red', 'blue', 'green', 'black', 'white'];
    $sizes = ['xs', 's', 'm', 'l', 'xl'];
    $variantItems = [];

    foreach ($productsResult->products as $pIdx => $product) {
        foreach ($colors as $cIdx => $color) {
            foreach ($sizes as $sIdx => $size) {
                $variantItems[] = new UpsertVariantItem(
                    product: $product->id,
                    sku: sprintf('PG-%02d-%s-%s', $pIdx + 1, strtoupper($color), strtoupper($size)),
                    axes: ['color' => $color, 'size' => $size],
                    attributes: ['fit' => ($cIdx + $sIdx) % 2 === 0 ? 'slim' : 'regular'],
                );
            }
        }
    }

    DB::flushQueryLog();
    DB::enableQueryLog();
    $variantsResult = Catalog::variants()->upsertMany(new UpsertVariants($variantItems));
    $queryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($variantsResult->createdCount)->toBe(1000)
        ->and($variantsResult->outboxEventCount)->toBe(1)
        ->and($queryCount)->toBeLessThanOrEqual(45);

    // Disjunctive facets on PG: selecting color=red does not zero out blue/green/black/white.
    $search = Catalog::search()->search(new CatalogSearchQuery(
        filters: [
            VariantFilter::axes(['color' => 'red']),
            AttributeFilter::equals('material', 'cotton'),
        ],
        facets: ['color', 'material', 'fit'],
        sort: SearchSort::HandleAsc,
        first: 5,
    ));

    expect($search->total)->toBeGreaterThan(0)
        ->and($search->facet('color')?->countFor('blue'))->toBeGreaterThan(0)
        ->and($search->facet('material')?->countFor('linen'))->toBeGreaterThan(0);

    // Exhaustive keyset pagination without duplicates or gaps.
    $seen = [];
    $cursor = null;
    do {
        $page = Catalog::search()->search(new CatalogSearchQuery(
            filters: [AttributeFilter::equals('material', 'cotton')],
            sort: SearchSort::HandleAsc,
            first: 5,
            after: $cursor,
        ));
        foreach ($page->items() as $item) {
            $seen[] = $item->id->value;
        }
        $cursor = $page->nextCursor();
    } while ($cursor !== null);

    expect(count($seen))->toBe($page->total)
        ->and(count(array_unique($seen)))->toBe($page->total);
});
