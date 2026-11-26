<?php

declare(strict_types=1);

use Commerz\Catalog\Attributes\Data\DefineAttribute;
use Commerz\Catalog\Categories\Data\AssignProductCategories;
use Commerz\Catalog\Categories\Data\CreateCategory;
use Commerz\Catalog\Collections\Data\CreateCollection;
use Commerz\Catalog\Collections\Data\SetCollectionProducts;
use Commerz\Catalog\Facades\Catalog;
use Commerz\Catalog\Listings\Data\PublishProduct;
use Commerz\Catalog\Listings\Data\UnpublishProduct;
use Commerz\Catalog\Options\Data\DefineOption;
use Commerz\Catalog\Products\Data\ArchiveProduct;
use Commerz\Catalog\Products\Data\CreateProduct;
use Commerz\Catalog\Products\Data\UpdateProduct;
use Commerz\Catalog\ProductTypes\Data\CreateProductType;
use Commerz\Catalog\Projection\Data\ListStorefrontProducts;
use Commerz\Catalog\Projection\Exceptions\StorefrontProductNotFound;
use Commerz\Catalog\Projection\Internal\StorefrontOutboxProjector;
use Commerz\Catalog\Tests\Support\CatalogEngineSqliteHarness;
use Commerz\Catalog\Variants\Data\CreateVariant;
use Commerz\Context\CommerceContext;
use Commerz\Measures\LocalizedText;
use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Outbox\Contracts\OutboxConsumer;
use Commerz\Outbox\Data\IntegrationEvent;
use Commerz\Ports\Prices\Contracts\PriceResolver;
use Commerz\Ports\Prices\Data\ResolvedPrice;
use Commerz\Ports\Prices\Exceptions\PriceNotFound;
use Commerz\Ports\Stock\Contracts\StockAvailability;
use Commerz\Ports\Stock\Data\AvailabilityAnswer;
use Commerz\Ports\Stock\Data\CheckAvailability;
use Commerz\Ports\Stock\Data\LineAvailability;
use Commerz\Stores\Contracts\Channels;
use Commerz\Stores\Contracts\Stores;
use Commerz\Stores\Data\CreateChannel;
use Commerz\Stores\Data\CreateStore;
use Illuminate\Support\Facades\DB;
use Packman\Plugins\Contracts\Contributions\Contributions;

beforeEach(function (): void {
    $booted = CatalogEngineSqliteHarness::boot('2026-11-26T14:00:00Z');
    $this->sqliteDbPath = $booted['dbPath'];
    $this->clock = $booted['clock'];
});

afterEach(function (): void {
    CatalogEngineSqliteHarness::cleanup($this->sqliteDbPath);
});

it('projects storefront rows via Outbox events (catalog.*, pricing.*, inventory.*) and serves single-row reads', function (): void {
    /** @var array<string, int> $pricesMinor */
    $pricesMinor = [];
    /** @var array<string, int> $stockUnits */
    $stockUnits = [];

    app()->instance(PriceResolver::class, new class($pricesMinor) implements PriceResolver
    {
        /** @var array<string, int> */
        public array $prices;

        /** @param array<string, int> $prices */
        public function __construct(array &$prices)
        {
            $this->prices = &$prices;
        }

        public function resolveMany(array $requests, CommerceContext $context): array
        {
            $out = [];
            foreach ($requests as $req) {
                $key = (string) $req->sellable;
                if (! isset($this->prices[$key])) {
                    throw new PriceNotFound($req->sellable, $context->currency);
                }
                $out[] = new ResolvedPrice(
                    Money::ofMinor($this->prices[$key], $context->currency),
                    null,
                    true,
                    'price_list:retail',
                );
            }

            return $out;
        }
    });

    app()->instance(StockAvailability::class, new class($stockUnits) implements StockAvailability
    {
        /** @var array<string, int> */
        public array $stock;

        /** @param array<string, int> $stock */
        public function __construct(array &$stock)
        {
            $this->stock = &$stock;
        }

        public function check(CheckAvailability $query): AvailabilityAnswer
        {
            return new AvailabilityAnswer(array_map(
                fn ($line): LineAvailability => new LineAvailability(
                    $line->sellable,
                    $line->quantity,
                    $this->stock[(string) $line->sellable] ?? 0,
                ),
                $query->lines,
            ));
        }
    });

    $store = app(Stores::class)->create(new CreateStore('main', 'Main', Currency::of('EUR')));
    $channel = app(Channels::class)->create(new CreateChannel(
        store: $store->id,
        code: 'web',
        name: 'Web',
        currencies: [Currency::of('EUR')],
        locales: ['en', 'de'],
        defaultLocale: 'en',
    ));

    Catalog::options()->define(new DefineOption(
        'size',
        new LocalizedText(['en' => 'Size', 'de' => 'Groesse']),
        [
            's' => new LocalizedText(['en' => 'Small', 'de' => 'Klein']),
            'l' => new LocalizedText(['en' => 'Large', 'de' => 'Gross']),
        ],
    ));
    Catalog::attributes()->define(new DefineAttribute('brand', 'text', LocalizedText::of('en', 'Brand'), filterable: true));
    Catalog::attributes()->define(new DefineAttribute('weight_class', 'text', LocalizedText::of('en', 'Weight'), filterable: true));

    $type = Catalog::productTypes()->create(new CreateProductType(
        code: 'jacket',
        name: LocalizedText::of('en', 'Jacket'),
        productAttributes: ['brand'],
        variantAxes: ['size'],
        overridableVariantAttributes: ['weight_class'],
    ));

    $product = CatalogEngineSqliteHarness::createProduct(new CreateProduct(
        $type->id,
        'parka',
        new LocalizedText(['en' => 'Arctic Parka', 'de' => 'Arktis Parka']),
        new LocalizedText(['en' => 'Warm coat', 'de' => 'Warmer Mantel']),
        attributes: ['brand' => 'Nord', 'weight_class' => 'medium'],
    ));

    $vSmall = Catalog::variants()->create(new CreateVariant($product->id, 'PARKA-S', ['size' => 's']));
    $vLarge = Catalog::variants()->create(new CreateVariant($product->id, 'PARKA-L', ['size' => 'l'], attributes: [
        'weight_class' => 'heavy',
    ]));

    $outerwear = Catalog::categories()->create(new CreateCategory('outerwear', LocalizedText::of('en', 'Outerwear')));
    Catalog::categories()->assign(new AssignProductCategories($product->id, [$outerwear->id]));

    $winter = Catalog::collections()->create(CreateCollection::manual('winter', LocalizedText::of('en', 'Winter')));
    Catalog::collections()->setProducts(new SetCollectionProducts($winter->id, [$product->id]));

    Catalog::listings()->publish(new PublishProduct($product->id, $channel->id));

    $pricesMinor[(string) $vSmall->sellable] = 12900;
    $pricesMinor[(string) $vLarge->sellable] = 14900;
    $stockUnits[(string) $vSmall->sellable] = 0;
    $stockUnits[(string) $vLarge->sellable] = 3;

    // Resolve the contributed OutboxConsumer through Packman Contributions.
    $consumer = app(Contributions::class)->get(OutboxConsumer::KIND, 'catalog.storefront_projection', OutboxConsumer::class);
    expect($consumer)->toBeInstanceOf(StorefrontOutboxProjector::class);

    // 1) catalog.* event materializes rows for both 'en' and 'de' locales.
    $consumer->consume(IntegrationEvent::of('catalog.product_published', 'product:'.$product->id->value, [
        'product_id' => $product->id->value,
        'channel_id' => $channel->id->value,
    ], occurredAt: $this->clock->now()));

    // Verify single-row storefront read executes EXACTLY 1 SQL SELECT query with zero joins!
    $queries = [];
    DB::connection('catalog_sqlite')->listen(static function ($q) use (&$queries): void {
        $queries[] = $q->sql;
    });

    $enView = Catalog::storefront()->get($channel->id, 'en', $product->id);
    expect($queries)->toHaveCount(1)
        ->and(strtolower($queries[0]))->not->toContain('join')
        ->and($enView->name)->toBe('Arctic Parka')
        ->and($enView->priceMinMinor)->toBe(12900)
        ->and($enView->priceMaxMinor)->toBe(14900)
        ->and($enView->currency)->toBe('EUR')
        ->and($enView->inStock)->toBeTrue()
        ->and($enView->productAttributes)->toBe(['brand' => 'Nord'])
        ->and($enView->categories)->toBe(['outerwear'])
        ->and($enView->collections)->toBe(['winter'])
        ->and($enView->variants[0]->options)->toBe(['Size' => 'Small'])
        ->and($enView->variants[0]->effectiveAttributes)->toBe(['weight_class' => 'medium'])
        ->and($enView->variants[0]->inStock)->toBeFalse()
        ->and($enView->variants[1]->options)->toBe(['Size' => 'Large'])
        ->and($enView->variants[1]->effectiveAttributes)->toBe(['weight_class' => 'heavy'])
        ->and($enView->variants[1]->inStock)->toBeTrue();

    $deView = Catalog::storefront()->findByHandle($channel->id, 'de', 'parka');
    expect($deView)->not->toBeNull()
        ->and($deView?->name)->toBe('Arktis Parka')
        ->and($deView?->variants[0]->options)->toBe(['Groesse' => 'Klein']);

    // 2) pricing.* event updates price_min_minor and price_max_minor.
    $pricesMinor[(string) $vSmall->sellable] = 9900;
    $consumer->consume(IntegrationEvent::of('pricing.prices_changed', 'price_list:01900000-0000-7000-8000-000000000001', [
        'sellables' => [(string) $vSmall->sellable],
    ], occurredAt: $this->clock->now()));

    $repriced = Catalog::storefront()->get($channel->id, 'en', $product->id);
    expect($repriced->priceMinMinor)->toBe(9900)
        ->and($repriced->priceMaxMinor)->toBe(14900);

    // 3) inventory.* event (`inventory.level_changed` by SKU, matching StockLedger) updates in_stock!
    $stockUnits[(string) $vLarge->sellable] = 0;
    $consumer->consume(IntegrationEvent::of('inventory.level_changed', 'stock_level:01900000-0000-7000-8000-000000000002', [
        'sku' => 'PARKA-L',
        'available' => 0,
    ], occurredAt: $this->clock->now()));

    $outOfStock = Catalog::storefront()->get($channel->id, 'en', $product->id);
    expect($outOfStock->inStock)->toBeFalse()
        ->and(Catalog::storefront()->list(new ListStorefrontProducts($channel->id, 'en', inStock: true))->items)->toBe([])
        ->and(Catalog::storefront()->list(new ListStorefrontProducts($channel->id, 'en', inStock: false, minPriceMinor: 10000))->items)->toHaveCount(1);

    // 4) Unpublishing or archiving removes stale storefront rows cleanly.
    Catalog::listings()->unpublish(new UnpublishProduct($product->id, $channel->id));
    $consumer->consume(IntegrationEvent::of('catalog.product_unpublished', 'product:'.$product->id->value, [
        'product_id' => $product->id->value,
        'channel_id' => $channel->id->value,
    ], occurredAt: $this->clock->now()));

    expect(Catalog::storefront()->find($channel->id, 'en', $product->id))->toBeNull()
        ->and(fn () => Catalog::storefront()->get($channel->id, 'en', $product->id))
        ->toThrow(StorefrontProductNotFound::class);

    // Re-publish, project, then archive product -> rows deleted across all channels/locales.
    Catalog::listings()->publish(new PublishProduct($product->id, $channel->id));
    Catalog::storefront()->projectChannel($channel->id);
    expect(Catalog::storefront()->find($channel->id, 'de', $product->id))->not->toBeNull();

    Catalog::products()->update(new UpdateProduct($product->id, name: new LocalizedText(['en' => 'Parka V2', 'de' => 'Parka V2'])));
    Catalog::products()->archive(new ArchiveProduct($product->id));
    $consumer->consume(IntegrationEvent::of('catalog.product_updated', 'product:'.$product->id->value, [], occurredAt: $this->clock->now()));

    expect(DB::connection('catalog_sqlite')->table('cmz_storefront_products')->count())->toBe(0);
});
