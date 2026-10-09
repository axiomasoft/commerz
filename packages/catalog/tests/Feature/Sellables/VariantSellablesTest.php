<?php

declare(strict_types=1);

use Commerz\Catalog\Attributes\Data\DefineAttribute;
use Commerz\Catalog\Attributes\Data\UpdateAttribute;
use Commerz\Catalog\Attributes\Enums\AttributeStatus;
use Commerz\Catalog\Categories\Data\AssignProductCategories;
use Commerz\Catalog\Categories\Data\CreateCategory;
use Commerz\Catalog\Facades\Catalog;
use Commerz\Catalog\Listings\Data\PublishProduct;
use Commerz\Catalog\Listings\Data\UnpublishProduct;
use Commerz\Catalog\Options\Data\DefineOption;
use Commerz\Catalog\Products\Data\ArchiveProduct;
use Commerz\Catalog\Products\Data\CreateProduct;
use Commerz\Catalog\Products\Data\UpdateProduct;
use Commerz\Catalog\Products\Enums\ProductStatus;
use Commerz\Catalog\ProductTypes\Data\CreateProductType;
use Commerz\Catalog\ProductTypes\Data\ListProductTypes;
use Commerz\Catalog\Variants\Data\ArchiveVariant;
use Commerz\Catalog\Variants\Data\CreateVariant;
use Commerz\Catalog\Variants\Data\GenerateVariants;
use Commerz\Catalog\Variants\Data\VariantView;
use Commerz\Context\CommerceContext;
use Commerz\Facades\Commerz;
use Commerz\Identity\SellableRef;
use Commerz\Measures\LocalizedText;
use Commerz\Measures\Measure;
use Commerz\Money\Currency;
use Commerz\Ports\Sellables\Enums\SellableKind;
use Commerz\Ports\Sellables\Exceptions\SellableUnavailable;
use Commerz\Schema\TableNames;
use Commerz\Stores\Contracts\Channels;
use Commerz\Stores\Contracts\Stores;
use Commerz\Stores\Data\ChannelView;
use Commerz\Stores\Data\CreateChannel;
use Commerz\Stores\Data\CreateStore;
use Commerz\Stores\Data\ListStores;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function selText(string $en, ?string $ru = null): LocalizedText
{
    $text = LocalizedText::of('en', $en);

    return $ru === null ? $text : $text->with('ru', $ru);
}

function selChannel(string $code = 'web'): ChannelView
{
    $stores = app(Stores::class);
    $store = $stores->list(new ListStores)->items[0] ?? $stores->create(new CreateStore('main', 'Main', Currency::of('RUB')));

    return app(Channels::class)->create(new CreateChannel($store->id, $code, ucfirst($code), [Currency::of('RUB')], ['en', 'ru']));
}

function selContext(ChannelView $channel, string $locale = 'en', ?DateTimeImmutable $at = null): CommerceContext
{
    return CommerceContext::for($channel->id, Currency::of('RUB'), $locale, $at);
}

/** A product of the axis-less `gift` type with its single variant, published in the channel unless told otherwise. */
function selGift(string $handle, ?ChannelView $channel, ?DateTimeImmutable $from = null, ?DateTimeImmutable $until = null): VariantView
{
    $type = Catalog::productTypes()->list(new ListProductTypes)->items[0] ?? Catalog::productTypes()->create(new CreateProductType('gift', selText('Gift')));
    $product = Catalog::products()->create(new CreateProduct($type->id, $handle, selText(ucfirst($handle))));

    if ($channel instanceof ChannelView) {
        Catalog::listings()->publish(new PublishProduct($product->id, $channel->id, $from, $until));
    }

    return Catalog::variants()->create(new CreateVariant($product->id, strtoupper($handle)));
}

function selReason(VariantView $variant, CommerceContext $context): ?string
{
    $snapshot = Commerz::sellables()->resolve($variant->sellable, $context);

    return $snapshot->purchasable ? null : $snapshot->unavailableReason;
}

it('builds the snapshot from the product, variant, options, attributes and categories', function (): void {
    $channel = selChannel();

    Catalog::options()->define(new DefineOption('color', selText('Color', 'Цвет'), ['red' => selText('Red', 'Красный'), 'blue' => selText('Blue')]));
    Catalog::options()->define(new DefineOption('size', selText('Size', 'Размер'), ['s' => selText('S'), 'm' => selText('M')]));
    Catalog::attributes()->define(new DefineAttribute('material', 'enum', selText('Material'), choices: ['cotton' => selText('Cotton')]));
    Catalog::attributes()->define(new DefineAttribute('density', 'measure', selText('Density'), config: ['unit' => 'gsm']));
    Catalog::attributes()->define(new DefineAttribute('pieces', 'integer', selText('Pieces')));
    Catalog::attributes()->define(new DefineAttribute('organic', 'boolean', selText('Organic')));
    Catalog::attributes()->define(new DefineAttribute('tags', 'multi_enum', selText('Tags'), choices: ['eco' => selText('Eco'), 'new' => selText('New')]));
    Catalog::attributes()->define(new DefineAttribute('caption', 'localized_text', selText('Caption')));
    Catalog::attributes()->define(new DefineAttribute('note', 'text', selText('Note')));
    $brand = Catalog::attributes()->define(new DefineAttribute('brand', 'text', selText('Brand')));

    $type = Catalog::productTypes()->create(new CreateProductType(
        'apparel',
        selText('Apparel'),
        productAttributes: ['material', 'density', 'pieces', 'organic', 'tags', 'caption', 'brand'],
        variantAttributes: ['note'],
        variantAxes: ['color', 'size'],
    ));
    $product = Catalog::products()->create(new CreateProduct($type->id, 'basic-tee', selText('Tee', 'Футболка'), attributes: [
        'material' => 'cotton', 'density' => Measure::of('180', 'gsm'), 'pieces' => 2, 'organic' => true,
        'tags' => ['eco', 'new'], 'caption' => selText('Soft'), 'brand' => 'acme',
    ]));
    $variant = Catalog::variants()->create(new CreateVariant(
        $product->id, 'TEE-RED-M', ['color' => 'red', 'size' => 'm'], attributes: ['note' => 'gift'], taxClass: 'vat-20', weightGrams: 250,
    ));
    Catalog::attributes()->update(new UpdateAttribute($brand->id, status: AttributeStatus::Retired));

    $apparel = Catalog::categories()->create(new CreateCategory('apparel', selText('Apparel')));
    $tees = Catalog::categories()->create(new CreateCategory('tees', selText('Tees'), $apparel->id));
    Catalog::categories()->create(new CreateCategory('sale', selText('Sale')));
    Catalog::categories()->assign(new AssignProductCategories($product->id, [$tees->id]));
    Catalog::listings()->publish(new PublishProduct($product->id, $channel->id));

    $en = Commerz::sellables()->resolve($variant->sellable, selContext($channel));
    $ru = Commerz::sellables()->resolve($variant->sellable, selContext($channel, 'ru'));

    expect($en->ref)->toEqual($variant->sellable)
        ->and($en->sku)->toBe('TEE-RED-M')
        ->and($en->name)->toBe('Tee')
        ->and($en->kind)->toBe(SellableKind::Physical)
        ->and($en->requiresShipping)->toBeTrue()
        ->and($en->purchasable)->toBeTrue()
        ->and($en->unavailableReason)->toBeNull()
        ->and($en->options)->toBe(['Color' => 'Red', 'Size' => 'M'])
        ->and(array_keys($en->options))->toBe(['Color', 'Size'])
        ->and($en->attributes)->toBe(['material' => 'cotton', 'note' => 'gift', 'organic' => true, 'pieces' => 2])
        ->and($en->categories)->toBe(['apparel', 'tees'])
        ->and($en->taxClass)->toBe('vat-20')
        ->and($en->weightGrams)->toBe(250)
        ->and($en->basePrice)->toBeNull()
        ->and($ru->name)->toBe('Футболка')
        ->and($ru->options)->toBe(['Цвет' => 'Красный', 'Размер' => 'M']);
});

it('reports the first failing reason, in the documented order', function (): void {
    $channel = selChannel();
    $other = selChannel('b2b');
    $context = selContext($channel);

    $draft = selGift('draft', $channel);
    Catalog::variants()->archive(new ArchiveVariant($draft->id));
    Catalog::products()->update(new UpdateProduct($draft->product, status: ProductStatus::Draft));
    Catalog::listings()->unpublish(new UnpublishProduct($draft->product, $channel->id));

    $archivedProduct = selGift('retired', $channel);
    Catalog::products()->archive(new ArchiveProduct($archivedProduct->product));

    $archived = selGift('archived', $channel);
    Catalog::variants()->archive(new ArchiveVariant($archived->id));
    Catalog::listings()->unpublish(new UnpublishProduct($archived->product, $channel->id));

    $unpublished = selGift('unpublished', $channel);
    Catalog::listings()->unpublish(new UnpublishProduct($unpublished->product, $channel->id));

    $future = new DateTimeImmutable('+1 day');
    $early = selGift('early', $channel, from: $future);
    $expired = selGift('expired', $channel, until: new DateTimeImmutable('-1 day'));
    DB::table(app(TableNames::class)->table('product_types'))->update(['kind' => 'ghost']);

    expect(selReason($draft, $context))->toBe('product_inactive')
        ->and(selReason($archivedProduct, $context))->toBe('product_inactive')
        ->and(selReason($archived, $context))->toBe('variant_archived')
        ->and(selReason($unpublished, $context))->toBe('not_listed')
        ->and(selReason(selGift('unlisted', null), $context))->toBe('not_listed')
        ->and(selReason(selGift('elsewhere', $other), $context))->toBe('not_listed')
        ->and(selReason($early, $context))->toBe('not_available_yet')
        ->and(selReason($expired, $context))->toBe('no_longer_available');
});

it('reports unsupported_kind last, after every other reason, and falls back to a physical kind', function (): void {
    $channel = selChannel();
    $context = selContext($channel);
    $ghost = selGift('ghost', $channel);
    $late = selGift('late', $channel, until: new DateTimeImmutable('-1 day'));
    DB::table(app(TableNames::class)->table('product_types'))->update(['kind' => 'acme.subscription']);

    $snapshot = Commerz::sellables()->resolve($ghost->sellable, $context);

    expect($snapshot->purchasable)->toBeFalse()
        ->and($snapshot->unavailableReason)->toBe('unsupported_kind')
        ->and($snapshot->kind)->toBe(SellableKind::Physical)
        ->and($snapshot->requiresShipping)->toBeTrue()
        ->and(selReason($late, $context))->toBe('no_longer_available');
});

it('maps each product kind to the sellable kind and shipping need', function (): void {
    $channel = selChannel();
    $context = selContext($channel);
    $cases = ['physical' => [SellableKind::Physical, true], 'digital' => [SellableKind::Digital, false], 'service' => [SellableKind::Service, false]];

    foreach ($cases as $kind => [$sellable, $ships]) {
        $type = Catalog::productTypes()->create(new CreateProductType("{$kind}-type", selText($kind), $kind));
        $product = Catalog::products()->create(new CreateProduct($type->id, "{$kind}-item", selText($kind)));
        Catalog::listings()->publish(new PublishProduct($product->id, $channel->id));
        $variant = Catalog::variants()->create(new CreateVariant($product->id, strtoupper($kind)));

        $snapshot = Commerz::sellables()->resolve($variant->sellable, $context);

        expect($snapshot->kind)->toBe($sellable)->and($snapshot->requiresShipping)->toBe($ships)->and($snapshot->purchasable)->toBeTrue();
    }
});

it('evaluates the listing window at context.at, from inclusive and until exclusive', function (): void {
    $channel = selChannel();
    $from = new DateTimeImmutable('2030-01-01 00:00:00+00:00');
    $until = new DateTimeImmutable('2030-02-01 00:00:00+00:00');
    $variant = selGift('windowed', $channel, $from, $until);
    $at = static fn (string $moment): CommerceContext => selContext($channel, at: new DateTimeImmutable($moment));

    expect(selReason($variant, $at('2029-12-31 23:59:59+00:00')))->toBe('not_available_yet')
        ->and(selReason($variant, $at('2030-01-01 00:00:00+00:00')))->toBeNull()
        ->and(selReason($variant, $at('2030-01-31 23:59:59+00:00')))->toBeNull()
        ->and(selReason($variant, $at('2030-02-01 00:00:00+00:00')))->toBe('no_longer_available')
        ->and(selReason($variant, $at('2030-01-01 03:00:00+03:00')))->toBeNull();
});

it('reports unknown ids, malformed ids and the unavailable ones the way the core does', function (): void {
    $channel = selChannel();
    $context = selContext($channel);
    $variant = selGift('known', $channel);
    $upper = SellableRef::of('variant', strtoupper($variant->id->value));

    expect(Commerz::sellables()->resolve($upper, $context)->sku)->toBe('KNOWN')
        ->and(array_keys(Commerz::sellables()->resolveMany([$upper], $context)))->toBe([(string) $upper]);

    foreach (['not-a-uuid', '0198f3d6-0000-7000-8000-000000000000', '42'] as $id) {
        expect(fn () => Commerz::sellables()->resolve(SellableRef::of('variant', $id), $context))
            ->toThrow(SellableUnavailable::class);
    }
});

it('resolves a batch of 20 variants in at most 6 queries', function (): void {
    $channel = selChannel();
    $colors = [];
    $sizes = [];

    foreach (range(1, 4) as $i) {
        $colors["c{$i}"] = selText("Color {$i}");
    }

    foreach (range(1, 5) as $i) {
        $sizes["s{$i}"] = selText("Size {$i}");
    }

    Catalog::options()->define(new DefineOption('color', selText('Color'), $colors));
    Catalog::options()->define(new DefineOption('size', selText('Size'), $sizes));
    Catalog::attributes()->define(new DefineAttribute('brand', 'text', selText('Brand')));
    $type = Catalog::productTypes()->create(new CreateProductType('apparel', selText('Apparel'), productAttributes: ['brand'], variantAxes: ['color', 'size']));
    $product = Catalog::products()->create(new CreateProduct($type->id, 'tee', selText('Tee'), attributes: ['brand' => 'acme']));
    $root = Catalog::categories()->create(new CreateCategory('root', selText('Root')));
    $leaf = Catalog::categories()->create(new CreateCategory('leaf', selText('Leaf'), $root->id));
    Catalog::categories()->assign(new AssignProductCategories($product->id, [$leaf->id]));
    Catalog::listings()->publish(new PublishProduct($product->id, $channel->id));
    $generated = Catalog::variants()->generate(new GenerateVariants($product->id, ['color' => array_keys($colors), 'size' => array_keys($sizes)], '{color}-{size}'));

    $refs = array_map(static fn (VariantView $variant): SellableRef => $variant->sellable, $generated->created);
    $queries = 0;
    DB::listen(static function () use (&$queries): void {
        $queries++;
    });

    $snapshots = Commerz::sellables()->resolveMany($refs, selContext($channel));

    expect($refs)->toHaveCount(20)
        ->and($snapshots)->toHaveCount(20)
        ->and($queries)->toBeLessThanOrEqual(6)
        ->and(array_values(array_unique(array_map(static fn ($snapshot): string => implode(',', $snapshot->categories), $snapshots))))->toBe(['leaf,root'])
        ->and(array_values(array_unique(array_map(static fn ($snapshot): bool => $snapshot->purchasable, $snapshots))))->toBe([true]);
});

it('preserves numeric attribute codes in snapshots and removes retired codes', function (): void {
    $channel = selChannel();
    $productAttribute = Catalog::attributes()->define(new DefineAttribute('7', 'integer', selText('Product')));
    Catalog::attributes()->define(new DefineAttribute('9', 'integer', selText('Variant')));
    $type = Catalog::productTypes()->create(new CreateProductType('numeric', selText('Numeric'), productAttributes: ['7'], variantAttributes: ['9']));
    $product = Catalog::products()->create(new CreateProduct($type->id, 'numeric', selText('Numeric'), attributes: ['7' => 70]));
    $variant = Catalog::variants()->create(new CreateVariant($product->id, 'NUMERIC', attributes: ['9' => 90]));
    Catalog::listings()->publish(new PublishProduct($product->id, $channel->id));

    expect(Commerz::sellables()->resolve($variant->sellable, selContext($channel))->attributes)->toBe([7 => 70, 9 => 90]);
    Catalog::attributes()->update(new UpdateAttribute($productAttribute->id, status: AttributeStatus::Retired));
    expect(Commerz::sellables()->resolve($variant->sellable, selContext($channel))->attributes)->toBe([9 => 90]);
});

it('keeps every axis when localized option names collide', function (bool $literalCollision): void {
    $channel = selChannel();
    Catalog::options()->define(new DefineOption('length', selText('Size'), ['short' => selText('Short')]));
    Catalog::options()->define(new DefineOption('width', selText('Size'), ['wide' => selText('Wide')]));
    $axes = ['length' => 'short', 'width' => 'wide'];
    if ($literalCollision) {
        Catalog::options()->define(new DefineOption('height', selText('Size [length]'), ['high' => selText('High')]));
        $axes['height'] = 'high';
    }
    $type = Catalog::productTypes()->create(new CreateProductType('dimensions', selText('Dimensions'), variantAxes: array_keys($axes)));
    $product = Catalog::products()->create(new CreateProduct($type->id, 'dimensions', selText('Dimensions')));
    $variant = Catalog::variants()->create(new CreateVariant($product->id, 'DIM', $axes));
    Catalog::listings()->publish(new PublishProduct($product->id, $channel->id));

    expect(Commerz::sellables()->resolve($variant->sellable, selContext($channel))->options)->toBe($literalCollision
        ? ['Size [length] [length]' => 'Short', 'Size [width]' => 'Wide', 'Size [length]' => 'High']
        : ['Size [length]' => 'Short', 'Size [width]' => 'Wide']);
})->with([false, true]);
