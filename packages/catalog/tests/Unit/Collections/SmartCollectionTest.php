<?php

declare(strict_types=1);

use Commerz\Catalog\Collections\Data\CollectionId;
use Commerz\Catalog\Collections\Data\CollectionItem;
use Commerz\Catalog\Collections\Data\CreateCollection;
use Commerz\Catalog\Collections\Data\ListCollectionProducts;
use Commerz\Catalog\Collections\Data\ListCollections;
use Commerz\Catalog\Collections\Data\PutCollectionProducts;
use Commerz\Catalog\Collections\Data\RemoveCollectionProducts;
use Commerz\Catalog\Collections\Data\SetCollectionProducts;
use Commerz\Catalog\Collections\Data\SmartCollection;
use Commerz\Catalog\Collections\Data\UpdateCollection;
use Commerz\Catalog\Collections\Enums\CollectionKind;
use Commerz\Catalog\Products\Data\AttributeFilter;
use Commerz\Catalog\Products\Data\ProductId;
use Commerz\Catalog\Products\Data\VariantFilter;
use Commerz\Catalog\Projection\Data\ListStorefrontProducts;
use Commerz\Errors\InvalidCommand;
use Commerz\Identity\SellableRef;
use Commerz\Measures\LocalizedText;
use Commerz\Measures\Measure;
use Commerz\Stores\Data\ChannelId;

it('round-trips SmartCollection attribute and variant filters through serialized JSON rules', function (): void {
    $smart = SmartCollection::of(
        AttributeFilter::equals('brand', 'acme'),
        AttributeFilter::in('tags', ['eco', 'new']),
        AttributeFilter::between('weight', Measure::of('100', 'g'), Measure::of('500', 'g')),
        AttributeFilter::between('released', new DateTimeImmutable('2026-01-01')),
        AttributeFilter::equals('designer', SellableRef::of('designer', 'designer-1')),
        AttributeFilter::equals('organic', true),
        AttributeFilter::between('stock', 1, 50),
        VariantFilter::axes(['color' => 'red', 'size' => 'm']),
    );

    $json = json_encode($smart->toArray(), JSON_THROW_ON_ERROR);
    $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    if (! is_array($decoded)) {
        throw new RuntimeException('Smart collection rules must decode to an array.');
    }
    $restored = SmartCollection::fromArray($decoded);

    expect($restored->toArray())->toBe($smart->toArray())
        ->and($restored->filters)->toHaveCount(8);
});

it('rejects empty or malformed SmartCollection rules and collection commands', function (): void {
    $id = CollectionId::generate();
    $p1 = ProductId::generate();

    expect(fn () => new SmartCollection([]))->toThrow(InvalidCommand::class)
        ->and(fn () => new SmartCollection(['bad']))->toThrow(InvalidCommand::class)
        ->and(fn () => SmartCollection::fromArray([]))->toThrow(InvalidCommand::class)
        ->and(fn () => SmartCollection::fromArray([['kind' => 'unknown']]))->toThrow(InvalidCommand::class)
        ->and(fn () => SmartCollection::fromArray([['kind' => 'attribute', 'attribute' => 'x', 'operator' => 'nope', 'values' => [1]]]))->toThrow(InvalidCommand::class)
        ->and(fn () => new CollectionItem($p1, -1))->toThrow(InvalidCommand::class)
        ->and(fn () => new SetCollectionProducts($id, [$p1, $p1]))->toThrow(InvalidCommand::class)
        ->and(fn () => new PutCollectionProducts($id, []))->toThrow(InvalidCommand::class)
        ->and(fn () => new RemoveCollectionProducts($id, []))->toThrow(InvalidCommand::class)
        ->and(fn () => new UpdateCollection($id, description: LocalizedText::of('en', 'x'), clearDescription: true))->toThrow(InvalidCommand::class)
        ->and(fn () => new ListCollections(first: 0))->toThrow(InvalidCommand::class)
        ->and(fn () => new ListCollectionProducts($id, first: 201))->toThrow(InvalidCommand::class)
        ->and(fn () => new ListStorefrontProducts(ChannelId::generate(), '   '))->toThrow(InvalidCommand::class)
        ->and(fn () => new ListStorefrontProducts(ChannelId::generate(), 'en', minPriceMinor: 500, maxPriceMinor: 100))->toThrow(InvalidCommand::class);

    $manual = CreateCollection::manual('featured', LocalizedText::of('en', 'Featured'));
    $smart = CreateCollection::smart('reds', LocalizedText::of('en', 'Reds'), SmartCollection::of(VariantFilter::axes(['color' => 'red'])));

    expect($manual->kind)->toBe(CollectionKind::Manual)
        ->and($smart->kind)->toBe(CollectionKind::Smart);
});
