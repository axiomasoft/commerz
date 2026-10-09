<?php

declare(strict_types=1);

use Commerz\Catalog\Attributes\Data\DefineAttribute;
use Commerz\Catalog\Attributes\Data\UpdateAttribute;
use Commerz\Catalog\Attributes\Enums\AttributeStatus;
use Commerz\Catalog\Attributes\Exceptions\AttributeNotFilterable;
use Commerz\Catalog\Attributes\Exceptions\AttributeNotFound;
use Commerz\Catalog\Attributes\Exceptions\InvalidAttributeValue;
use Commerz\Catalog\Categories\Data\AssignProductCategories;
use Commerz\Catalog\Categories\Data\CategoryId;
use Commerz\Catalog\Categories\Data\CreateCategory;
use Commerz\Catalog\Categories\Data\MoveCategory;
use Commerz\Catalog\Facades\Catalog;
use Commerz\Catalog\Listings\Data\PublishProduct;
use Commerz\Catalog\Listings\Data\UnpublishProduct;
use Commerz\Catalog\Options\Data\DefineOption;
use Commerz\Catalog\Products\Data\ArchiveProduct;
use Commerz\Catalog\Products\Data\AttributeFilter;
use Commerz\Catalog\Products\Data\CreateProduct;
use Commerz\Catalog\Products\Data\ListProducts;
use Commerz\Catalog\Products\Data\ProductView;
use Commerz\Catalog\Products\Data\VariantFilter;
use Commerz\Catalog\Products\Enums\ProductStatus;
use Commerz\Catalog\ProductTypes\Data\CreateProductType;
use Commerz\Catalog\ProductTypes\Data\ProductTypeView;
use Commerz\Catalog\Variants\Data\ArchiveVariant;
use Commerz\Catalog\Variants\Data\CreateVariant;
use Commerz\Errors\InvalidCommand;
use Commerz\Measures\LocalizedText;
use Commerz\Measures\Measure;
use Commerz\Money\Currency;
use Commerz\Operations\Contracts\Transactions;
use Commerz\Pagination\Page;
use Commerz\Stores\Contracts\Channels;
use Commerz\Stores\Contracts\Stores;
use Commerz\Stores\Data\ChannelView;
use Commerz\Stores\Data\CreateChannel;
use Commerz\Stores\Data\CreateStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function srchLabel(string $text): LocalizedText
{
    return LocalizedText::of('en', $text);
}

/**
 * Options color (red, blue) and size (s, m); an `apparel` type (axes color, size; product enum `material`, variant
 * text `fit`) and a `gadget` type without axes holding one filterable attribute of every scalar type.
 *
 * @return array{ProductTypeView, ProductTypeView}
 */
function srchTypes(): array
{
    Catalog::options()->define(new DefineOption('color', srchLabel('Color'), ['red' => srchLabel('Red'), 'blue' => srchLabel('Blue')]));
    Catalog::options()->define(new DefineOption('size', srchLabel('Size'), ['s' => srchLabel('S'), 'm' => srchLabel('M')]));

    $define = static fn (string $code, string $type, array $config = [], array $choices = [], bool $filterable = true) => Catalog::attributes()->define(
        new DefineAttribute($code, $type, srchLabel(ucfirst($code)), $config, array_map(srchLabel(...), $choices), $filterable),
    );

    $define('material', 'enum', choices: ['cotton' => 'Cotton', 'linen' => 'Linen']);
    $define('fit', 'text');
    $define('count', 'integer');
    $define('weight', 'decimal', ['scale' => 2]);
    $define('released', 'date');
    $define('density', 'measure', ['unit' => 'gsm']);
    $define('tags', 'multi_enum', choices: ['eco' => 'Eco', 'new' => 'New', 'sale' => 'Sale']);
    $define('organic', 'boolean');
    $define('brand', 'text');
    $define('note', 'text', filterable: false);

    return [
        Catalog::productTypes()->create(new CreateProductType(
            'apparel',
            srchLabel('Apparel'),
            productAttributes: ['material'],
            variantAttributes: ['fit'],
            variantAxes: ['color', 'size'],
        )),
        Catalog::productTypes()->create(new CreateProductType(
            'gadget',
            srchLabel('Gadget'),
            productAttributes: ['count', 'weight', 'released', 'density', 'tags', 'organic', 'brand', 'note'],
        )),
    ];
}

/**
 * @param  array<string, mixed>  $attributes
 * @param  list<array{0: array<string, string>, 1?: array<string, mixed>}>  $variants  axes and variant attributes
 */
function srchProduct(ProductTypeView $type, string $handle, array $attributes, array $variants): ProductView
{
    $product = Catalog::products()->create(new CreateProduct($type->id, $handle, srchLabel($handle), attributes: $attributes));

    foreach ($variants as $n => $variant) {
        Catalog::variants()->create(new CreateVariant($product->id, strtoupper($handle).'-'.$n, $variant[0], attributes: $variant[1] ?? []));
    }

    return $product;
}

/** A: red/M linen; B: red/S (fit slim) and blue/M (fit regular), cotton (catalog-design#filters). */
function srchApparel(ProductTypeView $apparel): void
{
    srchProduct($apparel, 'a', ['material' => 'linen'], [[['color' => 'red', 'size' => 'm']]]);
    srchProduct($apparel, 'b', ['material' => 'cotton'], [
        [['color' => 'red', 'size' => 's'], ['fit' => 'slim']],
        [['color' => 'blue', 'size' => 'm'], ['fit' => 'regular']],
    ]);
}

function srchGadgets(ProductTypeView $gadget): void
{
    srchProduct($gadget, 'g1', ['count' => 1, 'weight' => '1.5', 'released' => '2026-01-10', 'density' => Measure::of('150', 'gsm'), 'tags' => ['eco', 'new'], 'organic' => true, 'brand' => 'acme', 'note' => 'x'], [[[]]]);
    srchProduct($gadget, 'g2', ['count' => 5, 'weight' => '2.25', 'released' => '2026-03-01', 'density' => Measure::of('200', 'gsm'), 'tags' => ['new'], 'organic' => false, 'brand' => 'globex'], [[[]]]);
    srchProduct($gadget, 'g3', ['count' => 10, 'weight' => '10', 'released' => '2026-06-30', 'density' => Measure::of('300', 'gsm'), 'tags' => ['sale'], 'organic' => true, 'brand' => 'acme'], [[[]]]);
}

/** @param  Page<ProductView>  $page */
function srchHandles(Page $page): array
{
    return array_map(static fn (ProductView $p): string => $p->handle, $page->items);
}

/** @param  list<AttributeFilter|VariantFilter>  $filters */
function srchFind(array $filters): array
{
    return srchHandles(Catalog::products()->list(new ListProducts(filters: $filters)));
}

function srchChannel(): ChannelView
{
    $store = app(Stores::class)->create(new CreateStore('main', 'Main', Currency::of('RUB')));

    return app(Channels::class)->create(new CreateChannel($store->id, 'web', 'Web', [Currency::of('RUB')]));
}

function srchCode(Closure $call): string
{
    try {
        $call();
    } catch (InvalidCommand $e) {
        return $e->code();
    }

    return 'no error';
}

it('matches axes on one variant, not across variants (CAT-03)', function (): void {
    [$apparel] = srchTypes();
    srchApparel($apparel);

    expect(srchFind([VariantFilter::axes(['color' => 'red', 'size' => 'm'])]))->toBe(['a'])
        ->and(srchFind([VariantFilter::axes(['color' => 'red']), VariantFilter::axes(['size' => 'm'])]))->toBe(['a'])
        ->and(srchFind([VariantFilter::axes(['color' => 'red'])]))->toBe(['a', 'b'])
        ->and(srchFind([VariantFilter::axes(['color' => 'blue', 'size' => 's'])]))->toBe([])
        ->and(srchFind([VariantFilter::axes(['color' => 'red']), VariantFilter::axes(['color' => 'blue'])]))->toBe([])
        // A stale facet (unknown option or value) matches nothing instead of failing.
        ->and(srchFind([VariantFilter::axes(['color' => 'green'])]))->toBe([])
        ->and(srchFind([VariantFilter::axes(['shape' => 'round'])]))->toBe([])
        ->and(fn () => VariantFilter::axes([]))->toThrow(InvalidCommand::class)
        ->and(fn () => VariantFilter::axes(['Color' => 'red']))->toThrow(InvalidCommand::class);
});

it('combines product-level and variant-level attributes with axes on the same variant', function (): void {
    [$apparel] = srchTypes();
    srchApparel($apparel);

    expect(srchFind([AttributeFilter::equals('material', 'cotton'), VariantFilter::axes(['color' => 'blue'])]))->toBe(['b'])
        ->and(srchFind([AttributeFilter::equals('material', 'linen'), VariantFilter::axes(['color' => 'blue'])]))->toBe([])
        ->and(srchFind([AttributeFilter::in('material', ['cotton', 'linen'])]))->toBe(['a', 'b'])
        ->and(srchFind([AttributeFilter::equals('fit', 'regular'), VariantFilter::axes(['color' => 'red'])]))->toBe([])
        ->and(srchFind([AttributeFilter::equals('fit', 'regular'), VariantFilter::axes(['color' => 'blue'])]))->toBe(['b'])
        ->and(srchFind([AttributeFilter::in('fit', ['slim', 'regular']), AttributeFilter::equals('material', 'cotton')]))->toBe(['b']);
});

it('filters integers, decimals, dates and measures by equals, in and range in their canonical form', function (): void {
    [, $gadget] = srchTypes();
    srchGadgets($gadget);

    expect(srchFind([AttributeFilter::equals('count', 5)]))->toBe(['g2'])
        ->and(srchFind([AttributeFilter::equals('count', '5')]))->toBe(['g2'])
        ->and(srchFind([AttributeFilter::in('count', [1, 10])]))->toBe(['g1', 'g3'])
        ->and(srchFind([AttributeFilter::between('count', 2)]))->toBe(['g2', 'g3'])
        ->and(srchFind([AttributeFilter::between('count', max: 5)]))->toBe(['g1', 'g2'])
        ->and(srchFind([AttributeFilter::between('count', 2, 9)]))->toBe(['g2'])
        // Normalized by the decimal type: '1.5' finds the stored '1.50', '10' finds '10.00'.
        ->and(srchFind([AttributeFilter::equals('weight', '1.5')]))->toBe(['g1'])
        ->and(srchFind([AttributeFilter::in('weight', ['2.250', 10])]))->toBe(['g2', 'g3'])
        ->and(srchFind([AttributeFilter::between('weight', '1.6', '10.00')]))->toBe(['g2', 'g3'])
        ->and(srchFind([AttributeFilter::equals('released', '2026-03-01')]))->toBe(['g2'])
        ->and(srchFind([AttributeFilter::between('released', new DateTimeImmutable('2026-02-01'), '2026-06-30')]))->toBe(['g2', 'g3'])
        ->and(srchFind([AttributeFilter::equals('density', Measure::of('200', 'gsm'))]))->toBe(['g2'])
        ->and(srchFind([AttributeFilter::between('density', Measure::of('150', 'gsm'), Measure::of('200.000', 'gsm'))]))->toBe(['g1', 'g2'])
        ->and(fn () => srchFind([AttributeFilter::equals('weight', '1.555')]))->toThrow(InvalidAttributeValue::class)
        ->and(fn () => srchFind([AttributeFilter::equals('density', Measure::of('200', 'kg'))]))->toThrow(InvalidAttributeValue::class)
        ->and(fn () => AttributeFilter::between('count'))->toThrow(InvalidCommand::class);
});

it('filters choices, multi-choices, booleans and text', function (): void {
    [, $gadget] = srchTypes();
    srchGadgets($gadget);

    // multi_enum: equals = the list contains the choice (all of them for a list), in = any of them.
    expect(srchFind([AttributeFilter::equals('tags', 'new')]))->toBe(['g1', 'g2'])
        ->and(srchFind([AttributeFilter::equals('tags', ['new', 'eco'])]))->toBe(['g1'])
        ->and(srchFind([AttributeFilter::in('tags', ['eco', 'sale'])]))->toBe(['g1', 'g3'])
        ->and(srchFind([AttributeFilter::equals('organic', true)]))->toBe(['g1', 'g3'])
        ->and(srchFind([AttributeFilter::equals('organic', false)]))->toBe(['g2'])
        ->and(srchFind([AttributeFilter::equals('brand', 'globex')]))->toBe(['g2'])
        ->and(srchFind([AttributeFilter::in('brand', ['acme', 'initech'])]))->toBe(['g1', 'g3'])
        ->and(srchFind([AttributeFilter::in('brand', ['acme']), AttributeFilter::equals('tags', 'new')]))->toBe(['g1'])
        ->and(fn () => srchFind([AttributeFilter::equals('tags', 'unknown')]))->toThrow(InvalidAttributeValue::class)
        ->and(fn () => AttributeFilter::in('tags', []))->toThrow(InvalidCommand::class)
        ->and(fn () => AttributeFilter::equals('tags', null))->toThrow(InvalidCommand::class);
});

it('refuses filters on attributes that are unknown, not filterable or retired, and unsupported operators', function (): void {
    [, $gadget] = srchTypes();
    srchGadgets($gadget);
    $brand = Catalog::attributes()->findByCode('brand');

    expect(fn () => srchFind([AttributeFilter::equals('note', 'x')]))->toThrow(AttributeNotFilterable::class)
        ->and(fn () => srchFind([AttributeFilter::equals('nope', 'x')]))->toThrow(AttributeNotFound::class)
        ->and(srchCode(fn () => srchFind([AttributeFilter::in('organic', [true])])))->toBe('catalog.unsupported_filter')
        ->and(srchCode(fn () => srchFind([AttributeFilter::in('released', ['2026-03-01'])])))->toBe('catalog.unsupported_filter')
        ->and(srchCode(fn () => srchFind([AttributeFilter::between('tags', 'eco')])))->toBe('catalog.unsupported_filter');

    // Deprecated keeps the facts filterable; retired drops them.
    Catalog::attributes()->update(new UpdateAttribute($brand->id, status: AttributeStatus::Deprecated));
    expect(srchFind([AttributeFilter::equals('brand', 'globex')]))->toBe(['g2']);

    Catalog::attributes()->update(new UpdateAttribute($brand->id, status: AttributeStatus::Retired));
    expect(fn () => srchFind([AttributeFilter::equals('brand', 'globex')]))->toThrow(AttributeNotFilterable::class);
});

it('ignores archived variants', function (): void {
    [$apparel] = srchTypes();
    srchApparel($apparel);
    $blue = Catalog::variants()->findBySku('B-1');

    expect($blue->axes)->toBe(['color' => 'blue', 'size' => 'm']);

    Catalog::variants()->archive(new ArchiveVariant($blue->id));

    expect(srchFind([VariantFilter::axes(['color' => 'blue'])]))->toBe([])
        ->and(srchFind([AttributeFilter::equals('fit', 'regular')]))->toBe([])
        ->and(srchFind([AttributeFilter::equals('material', 'cotton')]))->toBe(['b']);

    Catalog::variants()->archive(new ArchiveVariant(Catalog::variants()->findBySku('B-0')->id));

    // A product filter still needs one active variant to hold on; without filters the product is listed.
    expect(srchFind([AttributeFilter::equals('material', 'cotton')]))->toBe([])
        ->and(srchFind([]))->toBe(['a', 'b']);
});

it('lists a channel: published, active and available at the given moment', function (): void {
    [$apparel, $gadget] = srchTypes();
    srchApparel($apparel);
    srchGadgets($gadget);
    $channel = srchChannel();
    $products = [];

    foreach (['a', 'b', 'g1', 'g2', 'g3'] as $handle) {
        $products[$handle] = Catalog::products()->findByHandle($handle);
    }

    foreach (['a', 'b', 'g1'] as $handle) {
        Catalog::listings()->publish(new PublishProduct($products[$handle]->id, $channel->id));
    }

    Catalog::listings()->publish(new PublishProduct($products['g2']->id, $channel->id, new DateTimeImmutable('2030-01-01 00:00:00+00:00')));
    Catalog::listings()->publish(new PublishProduct($products['g3']->id, $channel->id, null, new DateTimeImmutable('2026-01-01 00:00:00+00:00')));
    Catalog::listings()->unpublish(new UnpublishProduct($products['b']->id, $channel->id));
    Catalog::products()->archive(new ArchiveProduct($products['g1']->id));

    $list = static fn (?DateTimeImmutable $at = null, array $filters = []): array => srchHandles(Catalog::products()->list(new ListProducts(channel: $channel->id, filters: $filters, at: $at)));

    expect($list())->toBe(['a'])
        ->and($list(new DateTimeImmutable('2030-01-01 00:00:00+00:00')))->toBe(['a', 'g2'])
        ->and($list(new DateTimeImmutable('2029-12-31 23:59:59+00:00')))->toBe(['a'])
        ->and($list(new DateTimeImmutable('2025-12-31 23:59:59+00:00')))->toBe(['a', 'g3'])
        ->and($list(new DateTimeImmutable('2026-01-01 03:00:00+03:00')))->toBe(['a'])
        ->and($list(new DateTimeImmutable('2030-06-01'), [AttributeFilter::equals('count', 5)]))->toBe(['g2'])
        ->and(srchHandles(Catalog::products()->list(new ListProducts(channel: $channel->id, status: ProductStatus::Active))))->toBe(['a'])
        ->and(fn () => new ListProducts(channel: $channel->id, status: ProductStatus::Archived))->toThrow(InvalidCommand::class)
        ->and(fn () => new ListProducts(at: new DateTimeImmutable))->toThrow(InvalidCommand::class);
});

it('lists every status but archived by default and one status when asked', function (): void {
    [$apparel] = srchTypes();
    srchApparel($apparel);
    Catalog::products()->archive(new ArchiveProduct(Catalog::products()->findByHandle('a')->id));
    Catalog::products()->create(new CreateProduct($apparel->id, 'c', srchLabel('C'), status: ProductStatus::Draft));

    expect(srchHandles(Catalog::products()->list(new ListProducts)))->toBe(['b', 'c'])
        ->and(srchHandles(Catalog::products()->list(new ListProducts(status: ProductStatus::Archived))))->toBe(['a'])
        ->and(srchHandles(Catalog::products()->list(new ListProducts(status: ProductStatus::Draft))))->toBe(['c']);
});

it('lists a category with its descendants', function (): void {
    [$apparel, $gadget] = srchTypes();
    srchApparel($apparel);
    srchGadgets($gadget);
    $root = Catalog::categories()->create(new CreateCategory('all', srchLabel('All')));
    $tops = Catalog::categories()->create(new CreateCategory('tops', srchLabel('Tops'), $root->id));
    $tees = Catalog::categories()->create(new CreateCategory('tees', srchLabel('Tees'), $tops->id));
    $other = Catalog::categories()->create(new CreateCategory('other', srchLabel('Other')));

    Catalog::categories()->assign(new AssignProductCategories(Catalog::products()->findByHandle('a')->id, [$tees->id]));
    Catalog::categories()->assign(new AssignProductCategories(Catalog::products()->findByHandle('g1')->id, [$root->id, $other->id]));
    Catalog::categories()->assign(new AssignProductCategories(Catalog::products()->findByHandle('g2')->id, [$tops->id]));

    $in = static fn (CategoryId $category, array $filters = []): array => srchHandles(Catalog::products()->list(new ListProducts(category: $category, filters: $filters)));

    expect($in($root->id))->toBe(['a', 'g1', 'g2'])
        ->and($in($tops->id))->toBe(['a', 'g2'])
        ->and($in($tees->id))->toBe(['a'])
        ->and($in($other->id))->toBe(['g1'])
        ->and($in($root->id, [AttributeFilter::equals('tags', 'new')]))->toBe(['g1', 'g2'])
        ->and($in(CategoryId::generate()))->toBe([]);
});

it('pages the filtered products by handle without gaps or repeats', function (): void {
    [$apparel, $gadget] = srchTypes();
    srchApparel($apparel);
    srchGadgets($gadget);
    srchProduct($gadget, 'g0', ['tags' => ['new']], [[[]]]);
    srchProduct($gadget, 'g4', ['tags' => ['new', 'sale']], [[[]]]);

    $filters = [AttributeFilter::equals('tags', 'new')];
    $first = Catalog::products()->list(new ListProducts(filters: $filters, first: 2));
    $second = Catalog::products()->list(new ListProducts(filters: $filters, first: 2, after: $first->next));

    expect(srchHandles($first))->toBe(['g0', 'g1'])
        ->and($first->hasMore())->toBeTrue()
        ->and(srchHandles($second))->toBe(['g2', 'g4'])
        ->and($second->hasMore())->toBeFalse();
});

it('validates the list query', function (): void {
    expect(srchCode(fn () => new ListProducts(filters: ['color' => 'red'])))->toBe('catalog.invalid_filter')
        ->and(srchCode(fn () => new ListProducts(filters: [new stdClass])))->toBe('catalog.invalid_filter')
        ->and(srchCode(fn () => new ListProducts(filters: array_fill(0, 21, VariantFilter::axes(['color' => 'red'])))))->toBe('catalog.invalid_filter')
        ->and(srchCode(fn () => new ListProducts(first: 0)))->toBe('common.invalid_page_size')
        ->and(srchCode(fn () => AttributeFilter::in('count', ['a' => 1])))->toBe('catalog.invalid_filter')
        ->and(srchCode(fn () => AttributeFilter::in('count', array_fill(0, 101, 1))))->toBe('catalog.invalid_filter')
        ->and(srchCode(fn () => AttributeFilter::equals('Bad Code', 1)))->toBe('catalog.invalid_code');
});

it('compares complete text values after their shared indexed prefix on both attribute levels', function (): void {
    Catalog::attributes()->define(new DefineAttribute('long_product', 'text', srchLabel('Product'), ['maxLength' => 1000], filterable: true));
    Catalog::attributes()->define(new DefineAttribute('long_variant', 'text', srchLabel('Variant'), ['maxLength' => 1000], filterable: true));
    $type = Catalog::productTypes()->create(new CreateProductType('long_text', srchLabel('Long'), productAttributes: ['long_product'], variantAttributes: ['long_variant']));
    $prefix = str_repeat('я', 255);
    srchProduct($type, 'a', ['long_product' => $prefix.'A'], [[[], ['long_variant' => $prefix.'B']]]);
    srchProduct($type, 'b', ['long_product' => $prefix.'B'], [[[], ['long_variant' => $prefix.'A']]]);

    expect(srchFind([AttributeFilter::equals('long_product', $prefix.'A')]))->toBe(['a'])
        ->and(srchFind([AttributeFilter::in('long_product', [$prefix.'B', 'absent'])]))->toBe(['b'])
        ->and(srchFind([AttributeFilter::equals('long_variant', $prefix.'A')]))->toBe(['b'])
        ->and(srchFind([AttributeFilter::in('long_variant', [$prefix.'B'])]))->toBe(['a']);
});

it('reads category membership from one SQL snapshot while the tree and assignments change', function (): void {
    [, $gadget] = srchTypes();
    $root = Catalog::categories()->create(new CreateCategory('root', srchLabel('Root')));
    $other = Catalog::categories()->create(new CreateCategory('other', srchLabel('Other')));
    $child = Catalog::categories()->create(new CreateCategory('child', srchLabel('Child'), $root->id));
    $product = srchProduct($gadget, 'snapshot', [], [[[]]]);
    Catalog::categories()->assign(new AssignProductCategories($product->id, [$other->id]));
    $changed = false;

    DB::listen(function ($query) use (&$changed, $child, $other, $product): void {
        if ($changed || ! str_contains($query->sql, 'with recursive sub as')) {
            return;
        }
        $changed = true;
        app(Transactions::class)->run(function () use ($child, $other, $product): void {
            Catalog::categories()->move(new MoveCategory($child->id, $other->id));
            Catalog::categories()->assign(new AssignProductCategories($product->id, [$child->id]));
        });
    });

    // The product belongs to the other root both before and after the interleaving, never to root.
    expect(srchHandles(Catalog::products()->list(new ListProducts(category: $root->id))))->toBe([])
        ->and($changed)->toBeTrue();
});
