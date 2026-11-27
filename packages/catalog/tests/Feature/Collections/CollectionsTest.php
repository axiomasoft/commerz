<?php

declare(strict_types=1);

use Commerz\Catalog\Attributes\Data\DefineAttribute;
use Commerz\Catalog\Collections\Data\ArchiveCollection;
use Commerz\Catalog\Collections\Data\CollectionItem;
use Commerz\Catalog\Collections\Data\CollectionProductView;
use Commerz\Catalog\Collections\Data\CreateCollection;
use Commerz\Catalog\Collections\Data\ListCollectionProducts;
use Commerz\Catalog\Collections\Data\ListCollections;
use Commerz\Catalog\Collections\Data\PutCollectionProducts;
use Commerz\Catalog\Collections\Data\RemoveCollectionProducts;
use Commerz\Catalog\Collections\Data\SetCollectionProducts;
use Commerz\Catalog\Collections\Data\SmartCollection;
use Commerz\Catalog\Collections\Data\UpdateCollection;
use Commerz\Catalog\Collections\Enums\CollectionKind;
use Commerz\Catalog\Collections\Enums\CollectionStatus;
use Commerz\Catalog\Collections\Exceptions\CollectionArchived;
use Commerz\Catalog\Collections\Exceptions\InvalidCollectionOperation;
use Commerz\Catalog\Facades\Catalog;
use Commerz\Catalog\Listings\Data\PublishProduct;
use Commerz\Catalog\Options\Data\DefineOption;
use Commerz\Catalog\Products\Data\AttributeFilter;
use Commerz\Catalog\Products\Data\CreateProduct;
use Commerz\Catalog\Products\Data\VariantFilter;
use Commerz\Catalog\ProductTypes\Data\CreateProductType;
use Commerz\Catalog\Tests\Support\CatalogEngineSqliteHarness;
use Commerz\Catalog\Variants\Data\CreateVariant;
use Commerz\Measures\LocalizedText;
use Commerz\Money\Currency;
use Commerz\Stores\Contracts\Channels;
use Commerz\Stores\Contracts\Stores;
use Commerz\Stores\Data\CreateChannel;
use Commerz\Stores\Data\CreateStore;

beforeEach(function (): void {
    $booted = CatalogEngineSqliteHarness::boot();
    $this->sqliteDbPath = $booted['dbPath'];
});

afterEach(function (): void {
    CatalogEngineSqliteHarness::cleanup(CatalogEngineSqliteHarness::path($this->sqliteDbPath));
});

function colLabel(string $text): LocalizedText
{
    return LocalizedText::of('en', $text);
}

/**
 * @param  list<CollectionProductView>  $items
 * @return list<string>
 */
function colHandles(array $items): array
{
    return array_map(static fn (CollectionProductView $item): string => $item->product->handle, $items);
}

it('manages manual collections with explicit positioning, partial updates, removal, and cursor pagination', function (): void {
    $type = Catalog::productTypes()->create(new CreateProductType('basic', colLabel('Basic')));
    $alpha = CatalogEngineSqliteHarness::createProduct(new CreateProduct($type->id, 'alpha', colLabel('Alpha')));
    $bravo = CatalogEngineSqliteHarness::createProduct(new CreateProduct($type->id, 'bravo', colLabel('Bravo')));
    $charlie = CatalogEngineSqliteHarness::createProduct(new CreateProduct($type->id, 'charlie', colLabel('Charlie')));
    $delta = CatalogEngineSqliteHarness::createProduct(new CreateProduct($type->id, 'delta', colLabel('Delta')));

    $featured = Catalog::collections()->create(CreateCollection::manual(
        'featured',
        colLabel('Featured'),
        colLabel('Homepage featured items'),
    ));

    expect($featured->kind)->toBe(CollectionKind::Manual)
        ->and($featured->smart)->toBeNull();

    $assigned = Catalog::collections()->setProducts(new SetCollectionProducts($featured->id, [
        new CollectionItem($charlie->id, 20),
        new CollectionItem($alpha->id, 5),
        new CollectionItem($delta->id, 10),
        new CollectionItem($bravo->id, 10),
    ]));

    expect(array_map(static fn (CollectionProductView $i): array => [$i->product->handle, $i->position], $assigned))->toBe([
        ['alpha', 5],
        ['bravo', 10],
        ['delta', 10],
        ['charlie', 20],
    ]);

    // Paginate by (position, handle) without gaps or duplicates even when bravo & delta share position 10.
    $page1 = Catalog::collections()->products(new ListCollectionProducts($featured->id, first: 2));
    $page2 = Catalog::collections()->products(new ListCollectionProducts($featured->id, first: 2, after: $page1->next));

    expect(colHandles($page1->items))->toBe(['alpha', 'bravo'])
        ->and($page1->hasMore())->toBeTrue()
        ->and(colHandles($page2->items))->toBe(['delta', 'charlie'])
        ->and($page2->hasMore())->toBeFalse();

    // Reposition charlie to 1 and remove bravo.
    Catalog::collections()->putProducts(new PutCollectionProducts($featured->id, [
        new CollectionItem($charlie->id, 1),
    ]));
    Catalog::collections()->removeProducts(new RemoveCollectionProducts($featured->id, [$bravo->id]));

    $reordered = Catalog::collections()->products(new ListCollectionProducts($featured->id));
    expect(colHandles($reordered->items))->toBe(['charlie', 'alpha', 'delta'])
        ->and(Catalog::collections()->ofProduct($alpha->id))->toHaveCount(1);

    // Channel visibility filtering on manual collections.
    $store = app(Stores::class)->create(new CreateStore('main', 'Main', Currency::of('EUR')));
    $channel = app(Channels::class)->create(new CreateChannel($store->id, 'web', 'Web', [Currency::of('EUR')]));
    Catalog::listings()->publish(new PublishProduct($delta->id, $channel->id));
    Catalog::listings()->publish(new PublishProduct($charlie->id, $channel->id));

    $channelItems = Catalog::collections()->products(new ListCollectionProducts($featured->id, channel: $channel->id));
    expect(colHandles($channelItems->items))->toBe(['charlie', 'delta']);

    // Manual collection rejects smart rule updates; archived collection rejects mutations.
    expect(fn () => Catalog::collections()->update(new UpdateCollection(
        $featured->id,
        smart: SmartCollection::of(VariantFilter::axes(['color' => 'red'])),
    )))->toThrow(InvalidCollectionOperation::class);

    $archived = Catalog::collections()->archive(new ArchiveCollection($featured->id));
    expect($archived->status)->toBe(CollectionStatus::Archived)
        ->and(Catalog::collections()->list(new ListCollections)->items)->toBe([])
        ->and(Catalog::collections()->list(new ListCollections(includeArchived: true))->items)->toHaveCount(1)
        ->and(fn () => Catalog::collections()->setProducts(new SetCollectionProducts($featured->id, [$alpha->id])))
        ->toThrow(CollectionArchived::class);
});

it('evaluates SmartCollection dynamically from serialized AttributeFilter and VariantFilter rules', function (): void {
    Catalog::options()->define(new DefineOption('color', colLabel('Color'), [
        'red' => colLabel('Red'),
        'blue' => colLabel('Blue'),
    ]));
    Catalog::options()->define(new DefineOption('size', colLabel('Size'), [
        's' => colLabel('S'),
        'm' => colLabel('M'),
    ]));

    Catalog::attributes()->define(new DefineAttribute('season', 'enum', colLabel('Season'), choices: [
        'summer' => colLabel('Summer'),
        'winter' => colLabel('Winter'),
    ], filterable: true));
    Catalog::attributes()->define(new DefineAttribute('price_tier', 'integer', colLabel('Tier'), filterable: true));

    $type = Catalog::productTypes()->create(new CreateProductType(
        code: 'dress',
        name: colLabel('Dress'),
        productAttributes: ['season'],
        variantAxes: ['color', 'size'],
        overridableVariantAttributes: ['price_tier'],
    ));

    // p1: summer, tier 2, has single variant red/M -> matches (season=summer AND color=red AND size=m AND tier in 1..3)
    $p1 = CatalogEngineSqliteHarness::createProduct(new CreateProduct($type->id, 'aurora', colLabel('Aurora'), attributes: [
        'season' => 'summer',
        'price_tier' => 2,
    ]));
    Catalog::variants()->create(new CreateVariant($p1->id, 'AUR-RM', ['color' => 'red', 'size' => 'm']));

    // p2: summer, tier 2, has red/S and blue/M -> fails CAT-03 (no single variant is both red AND M)
    $p2 = CatalogEngineSqliteHarness::createProduct(new CreateProduct($type->id, 'breeze', colLabel('Breeze'), attributes: [
        'season' => 'summer',
        'price_tier' => 2,
    ]));
    Catalog::variants()->create(new CreateVariant($p2->id, 'BRZ-RS', ['color' => 'red', 'size' => 's']));
    Catalog::variants()->create(new CreateVariant($p2->id, 'BRZ-BM', ['color' => 'blue', 'size' => 'm']));

    // p3: winter -> does not match season=summer
    $p3 = CatalogEngineSqliteHarness::createProduct(new CreateProduct($type->id, 'crest', colLabel('Crest'), attributes: [
        'season' => 'winter',
        'price_tier' => 2,
    ]));
    Catalog::variants()->create(new CreateVariant($p3->id, 'CRS-RM', ['color' => 'red', 'size' => 'm']));

    $smart = Catalog::collections()->create(CreateCollection::smart(
        'summer-red-m',
        colLabel('Summer Red M'),
        SmartCollection::of(
            AttributeFilter::equals('season', 'summer'),
            AttributeFilter::between('price_tier', 1, 3),
            VariantFilter::axes(['color' => 'red', 'size' => 'm']),
        ),
    ));

    expect($smart->kind)->toBe(CollectionKind::Smart)
        ->and($smart->smart?->filters)->toHaveCount(3)
        ->and(fn () => Catalog::collections()->setProducts(new SetCollectionProducts($smart->id, [$p1->id])))
        ->toThrow(InvalidCollectionOperation::class);

    $matched = Catalog::collections()->products(new ListCollectionProducts($smart->id));
    expect(colHandles($matched->items))->toBe(['aurora'])
        ->and($matched->items[0]->position)->toBeNull();

    // Update smart rules to relax size axis -> both aurora and breeze now match dynamically!
    Catalog::collections()->update(new UpdateCollection(
        collection: $smart->id,
        smart: SmartCollection::of(
            AttributeFilter::equals('season', 'summer'),
            VariantFilter::axes(['color' => 'red']),
        ),
    ));

    $updatedMatches = Catalog::collections()->products(new ListCollectionProducts($smart->id));
    expect(colHandles($updatedMatches->items))->toBe(['aurora', 'breeze']);
});
