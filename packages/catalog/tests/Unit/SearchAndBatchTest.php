<?php

declare(strict_types=1);

use Commerz\Catalog\Common\Exceptions\DuplicateCode;
use Commerz\Catalog\Products\Data\AttributeFilter;
use Commerz\Catalog\Products\Data\ProductId;
use Commerz\Catalog\Products\Data\UpsertProductItem;
use Commerz\Catalog\Products\Data\UpsertProducts;
use Commerz\Catalog\Products\Enums\ProductStatus;
use Commerz\Catalog\ProductTypes\Data\ProductTypeId;
use Commerz\Catalog\Search\Contracts\CatalogSearchPort;
use Commerz\Catalog\Search\Data\CatalogSearchQuery;
use Commerz\Catalog\Search\Data\CatalogSearchResult;
use Commerz\Catalog\Search\Data\FacetDistribution;
use Commerz\Catalog\Search\Data\FacetRequest;
use Commerz\Catalog\Search\Data\FacetValueCount;
use Commerz\Catalog\Search\Enums\FacetKind;
use Commerz\Catalog\Search\Enums\SearchSort;
use Commerz\Catalog\Search\Internal\CatalogSearchOutboxSubscriber;
use Commerz\Catalog\Search\Internal\SearchCursor;
use Commerz\Catalog\Variants\Data\UpsertVariantItem;
use Commerz\Catalog\Variants\Data\UpsertVariants;
use Commerz\Errors\InvalidCommand;
use Commerz\Measures\LocalizedText;
use Commerz\Outbox\Data\IntegrationEvent;
use Commerz\Outbox\Events\IntegrationEventDelivered;
use Commerz\Pagination\Cursor;
use Commerz\Pagination\Page;
use Commerz\Stores\Data\ChannelId;

it('validates CatalogSearchQuery fields and normalizes string facets', function (): void {
    $query = new CatalogSearchQuery(
        term: '  linen shirt  ',
        locale: 'en-US',
        filters: [AttributeFilter::equals('material', 'linen')],
        facets: ['color', FacetRequest::attribute('material', maxValues: 20, disjunctive: false)],
        sort: SearchSort::Relevance,
        first: 50,
    );

    expect($query->term)->toBe('linen shirt')
        ->and($query->locale)->toBe('en-US')
        ->and($query->facets)->toHaveCount(2)
        ->and($query->facets[0]->code)->toBe('color')
        ->and($query->facets[0]->kind)->toBe(FacetKind::Auto)
        ->and($query->facets[0]->disjunctive)->toBeTrue()
        ->and($query->facets[1]->code)->toBe('material')
        ->and($query->facets[1]->kind)->toBe(FacetKind::Attribute)
        ->and($query->facets[1]->disjunctive)->toBeFalse()
        ->and((new CatalogSearchQuery(term: '   '))->term)->toBeNull();

    expect(fn () => new CatalogSearchQuery(first: 0))->toThrow(InvalidCommand::class)
        ->and(fn () => new CatalogSearchQuery(first: 201))->toThrow(InvalidCommand::class)
        ->and(fn () => new CatalogSearchQuery(term: str_repeat('a', 256)))->toThrow(InvalidCommand::class)
        ->and(fn () => new CatalogSearchQuery(locale: 'bad_locale!'))->toThrow(InvalidCommand::class)
        ->and(fn () => new CatalogSearchQuery(facets: ['color', 'color']))->toThrow(InvalidCommand::class)
        ->and(fn () => new CatalogSearchQuery(facets: [123]))->toThrow(InvalidCommand::class)
        ->and(fn () => new CatalogSearchQuery(at: new DateTimeImmutable('2026-01-01T00:00:00Z')))->toThrow(InvalidCommand::class)
        ->and(fn () => new CatalogSearchQuery(channel: ChannelId::generate(), status: ProductStatus::Archived))->toThrow(InvalidCommand::class)
        ->and(fn () => new FacetRequest('color', maxValues: 0))->toThrow(InvalidCommand::class)
        ->and(fn () => new FacetValueCount('red', -1))->toThrow(InvalidCommand::class);
});

it('exposes FacetDistribution and CatalogSearchResult helpers', function (): void {
    $dist = new FacetDistribution(
        code: 'color',
        kind: FacetKind::Option,
        disjunctive: true,
        values: [
            new FacetValueCount('red', 5, selected: true),
            new FacetValueCount('blue', 3, selected: false),
        ],
    );

    $result = new CatalogSearchResult(new Page([], null), 8, ['color' => $dist]);

    expect($dist->countFor('red'))->toBe(5)
        ->and($dist->countFor('blue'))->toBe(3)
        ->and($dist->countFor('green'))->toBe(0)
        ->and($dist->counts())->toBe(['red' => 5, 'blue' => 3])
        ->and($result->facet('color'))->toBe($dist)
        ->and($result->facet('missing'))->toBeNull()
        ->and($result->items())->toBe([])
        ->and($result->hasMore())->toBeFalse()
        ->and($result->nextCursor())->toBeNull();
});

it('round-trips SearchCursor for every SearchSort mode and rejects tampered cursors', function (): void {
    $id = ProductId::generate()->value;

    foreach ([
        new SearchCursor(SearchSort::HandleAsc, $id, 'shirt-a'),
        new SearchCursor(SearchSort::HandleDesc, $id, 'shirt-z'),
        new SearchCursor(SearchSort::Newest, $id, 'shirt-n', createdAt: '2026-11-26 12:00:00.000000+00'),
        new SearchCursor(SearchSort::Oldest, $id, 'shirt-o', createdAt: '2026-11-26 12:00:00.000000+00'),
        new SearchCursor(SearchSort::Relevance, $id, 'shirt-r', relevance: 140),
    ] as $cursor) {
        $encoded = $cursor->toCursor();
        $decoded = SearchCursor::decode($encoded, $cursor->sort);

        expect($decoded->sort)->toBe($cursor->sort)
            ->and($decoded->id)->toBe($cursor->id)
            ->and($decoded->handle)->toBe($cursor->handle)
            ->and($decoded->createdAt)->toBe($cursor->createdAt)
            ->and($decoded->relevance)->toBe($cursor->relevance);
    }

    $handleCursor = (new SearchCursor(SearchSort::HandleAsc, $id, 'shirt-a'))->toCursor();

    expect(fn () => SearchCursor::decode($handleCursor, SearchSort::HandleDesc))->toThrow(InvalidCommand::class)
        ->and(fn () => SearchCursor::decode(Cursor::after('not-json'), SearchSort::HandleAsc))->toThrow(InvalidCommand::class)
        ->and(fn () => SearchCursor::decode(Cursor::after('{"s":"handle_asc","id":"not-uuid","h":"a"}'), SearchSort::HandleAsc))->toThrow(InvalidCommand::class);
});

it('validates UpsertProducts and UpsertVariants batch invariants upfront', function (): void {
    $type = ProductTypeId::generate();
    $product = ProductId::generate();
    $name = LocalizedText::of('en', 'Item');

    expect(fn () => new UpsertProducts([
        new UpsertProductItem($type, 'dup-handle', $name),
        new UpsertProductItem($type, 'dup-handle', $name),
    ]))->toThrow(DuplicateCode::class)
        ->and(fn () => new UpsertProducts(['not-an-item']))->toThrow(InvalidCommand::class)
        ->and(fn () => new UpsertVariants([
            new UpsertVariantItem($product, 'SKU-1'),
            new UpsertVariantItem($product, 'SKU-1'),
        ]))->toThrow(DuplicateCode::class)
        ->and(fn () => new UpsertVariants([
            new UpsertVariantItem($product, 'SKU-1', barcode: 'BC-1'),
            new UpsertVariantItem($product, 'SKU-2', barcode: 'BC-1'),
        ]))->toThrow(DuplicateCode::class)
        ->and(fn () => new UpsertVariantItem($product, 'SKU-1', weightGrams: -1))->toThrow(InvalidCommand::class);
});

it('forwards single and compressed batch outbox events to external CatalogSearchPort adapters', function (): void {
    $adapter = new class implements CatalogSearchPort
    {
        /** @var list<list<string>> */
        public array $indexed = [];

        /** @var list<list<string>> */
        public array $removed = [];

        public function search(CatalogSearchQuery $query): CatalogSearchResult
        {
            return new CatalogSearchResult(new Page([], null), 0);
        }

        public function indexProducts(array $productIds): void
        {
            $this->indexed[] = array_map(static fn (ProductId $id): string => $id->value, $productIds);
        }

        public function removeProducts(array $productIds): void
        {
            $this->removed[] = array_map(static fn (ProductId $id): string => $id->value, $productIds);
        }
    };

    $subscriber = new CatalogSearchOutboxSubscriber($adapter);
    $p1 = ProductId::generate()->value;
    $p2 = ProductId::generate()->value;
    $p3 = ProductId::generate()->value;
    $ts = new DateTimeImmutable('2026-11-26T12:00:00Z');

    $subscriber->handle(new IntegrationEventDelivered(IntegrationEvent::of('catalog.product_created', "product:{$p1}", ['product_id' => $p1], occurredAt: $ts)));
    $subscriber->handle(new IntegrationEventDelivered(IntegrationEvent::of('catalog.product_updated', "product:{$p2}", ['product_id' => $p2, 'status' => 'archived'], occurredAt: $ts)));
    $subscriber->handle(new IntegrationEventDelivered(IntegrationEvent::of('catalog.products_upserted', 'catalog:batch', [
        'product_ids' => [$p1, $p2, $p3, $p1],
        'archived_ids' => [$p3],
    ], occurredAt: $ts)));
    $subscriber->handle(new IntegrationEventDelivered(IntegrationEvent::of('catalog.variants_upserted', 'catalog:batch', [
        'product_ids' => [$p2, $p2, 'not-a-uuid'],
    ], occurredAt: $ts)));
    $subscriber->handle(new IntegrationEventDelivered(IntegrationEvent::of('sales.order_placed', "order:{$p1}", ['product_id' => $p1], occurredAt: $ts)));

    expect($adapter->indexed)->toBe([
        [$p1],
        [$p1, $p2],
        [$p2],
    ])->and($adapter->removed)->toBe([
        [$p2],
        [$p3],
    ]);
});
