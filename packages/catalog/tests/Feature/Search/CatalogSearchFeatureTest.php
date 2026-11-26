<?php

declare(strict_types=1);

use Commerz\Catalog\Categories\Data\CategoryId;
use Commerz\Catalog\Facades\Catalog;
use Commerz\Catalog\Products\Data\AttributeFilter;
use Commerz\Catalog\Products\Data\ProductId;
use Commerz\Catalog\Products\Data\ProductView;
use Commerz\Catalog\Products\Data\UpsertProductItem;
use Commerz\Catalog\Products\Data\UpsertProducts;
use Commerz\Catalog\Products\Data\VariantFilter;
use Commerz\Catalog\ProductTypes\Data\ProductTypeId;
use Commerz\Catalog\Search\Contracts\CatalogSearchPort;
use Commerz\Catalog\Search\Data\CatalogSearchQuery;
use Commerz\Catalog\Search\Data\CatalogSearchResult;
use Commerz\Catalog\Search\Data\FacetRequest;
use Commerz\Catalog\Search\Enums\FacetKind;
use Commerz\Catalog\Search\Enums\SearchSort;
use Commerz\Catalog\Search\Internal\CatalogSearchOutboxSubscriber;
use Commerz\Catalog\Variants\Data\UpsertVariantItem;
use Commerz\Catalog\Variants\Data\UpsertVariants;
use Commerz\Catalog\Variants\Enums\VariantStatus;
use Commerz\Configuration\CommerzOptions;
use Commerz\Identity\Uuid7;
use Commerz\Measures\LocalizedText;
use Commerz\Operations\Contracts\Transactions;
use Commerz\Outbox\Data\IntegrationEvent;
use Commerz\Outbox\Events\IntegrationEventDelivered;
use Commerz\Pagination\Page;
use Commerz\Schema\TableNames;
use Commerz\Stores\Data\ChannelId;
use Commerz\Testkit\Fakes\FrozenClock;
use Commerz\Time\Contracts\Clock;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class SqliteCatalogSearchTransactions implements Transactions
{
    public function run(callable $work, int $lockTimeoutMs = 3000): mixed
    {
        return DB::connection('catalog_search_sqlite')->transaction(static fn () => $work());
    }

    public function inTransaction(): bool
    {
        return DB::connection('catalog_search_sqlite')->transactionLevel() > 0;
    }
}

function initCatalogSearchSqliteSchema(): void
{
    $schema = Schema::connection('catalog_search_sqlite');

    $schema->create('cmz_outbox_messages', static function (Blueprint $table): void {
        $table->increments('id');
        $table->string('event_id', 36)->unique();
        $table->string('type', 191);
        $table->integer('version')->default(1);
        $table->string('aggregate_ref', 191);
        $table->text('payload');
        $table->string('occurred_at', 40);
        $table->string('available_at', 40)->nullable();
        $table->string('delivered_at', 40)->nullable();
        $table->integer('attempts')->default(0);
        $table->text('last_error')->nullable();
    });

    $schema->create('cmz_attributes', static function (Blueprint $table): void {
        $table->string('id', 36)->primary();
        $table->string('code', 64)->unique();
        $table->string('type', 64);
        $table->text('label');
        $table->text('config')->default('{}');
        $table->boolean('filterable')->default(false);
        $table->string('status', 16)->default('active');
        $table->string('created_at', 40);
        $table->string('updated_at', 40);
    });

    $schema->create('cmz_attribute_choices', static function (Blueprint $table): void {
        $table->string('id', 36)->primary();
        $table->string('attribute_id', 36);
        $table->string('code', 64);
        $table->text('label');
        $table->integer('position')->default(0);
        $table->unique(['attribute_id', 'code']);
    });

    $schema->create('cmz_options', static function (Blueprint $table): void {
        $table->string('id', 36)->primary();
        $table->string('code', 64)->unique();
        $table->text('label');
        $table->string('created_at', 40);
        $table->string('updated_at', 40);
    });

    $schema->create('cmz_option_values', static function (Blueprint $table): void {
        $table->string('id', 36)->primary();
        $table->string('option_id', 36);
        $table->string('code', 64);
        $table->text('label');
        $table->integer('position')->default(0);
        $table->unique(['option_id', 'code']);
    });

    $schema->create('cmz_product_types', static function (Blueprint $table): void {
        $table->string('id', 36)->primary();
        $table->string('code', 64)->unique();
        $table->string('kind', 64)->default('physical');
        $table->text('label');
        $table->string('status', 16)->default('active');
        $table->string('created_at', 40);
        $table->string('updated_at', 40);
    });

    $schema->create('cmz_product_type_attributes', static function (Blueprint $table): void {
        $table->string('product_type_id', 36);
        $table->string('attribute_id', 36);
        $table->string('level', 32);
        $table->boolean('required')->default(false);
        $table->integer('position')->default(0);
        $table->primary(['product_type_id', 'attribute_id']);
    });

    $schema->create('cmz_product_type_options', static function (Blueprint $table): void {
        $table->string('product_type_id', 36);
        $table->string('option_id', 36);
        $table->integer('position')->default(0);
        $table->primary(['product_type_id', 'option_id']);
    });

    $schema->create('cmz_products', static function (Blueprint $table): void {
        $table->string('id', 36)->primary();
        $table->string('product_type_id', 36);
        $table->string('handle', 191)->unique();
        $table->text('name');
        $table->text('description')->nullable();
        $table->string('status', 16)->default('active');
        $table->text('attributes')->default('{}');
        $table->string('created_at', 40);
        $table->string('updated_at', 40);
    });

    $schema->create('cmz_product_options', static function (Blueprint $table): void {
        $table->string('product_id', 36);
        $table->string('option_id', 36);
        $table->integer('position')->default(0);
        $table->primary(['product_id', 'option_id']);
    });

    $schema->create('cmz_variants', static function (Blueprint $table): void {
        $table->string('id', 36)->primary();
        $table->string('product_id', 36);
        $table->string('sku', 191)->unique();
        $table->string('barcode', 64)->nullable()->unique();
        $table->string('option_signature', 512)->default('');
        $table->string('status', 16)->default('active');
        $table->integer('position')->default(0);
        $table->text('attributes')->default('{}');
        $table->string('tax_class', 64)->nullable();
        $table->integer('weight_grams')->nullable();
        $table->integer('length_mm')->nullable();
        $table->integer('width_mm')->nullable();
        $table->integer('height_mm')->nullable();
        $table->string('created_at', 40);
        $table->string('updated_at', 40);
        $table->unique(['product_id', 'option_signature']);
    });

    $schema->create('cmz_variant_option_values', static function (Blueprint $table): void {
        $table->string('variant_id', 36);
        $table->string('product_id', 36);
        $table->string('option_id', 36);
        $table->string('option_value_id', 36);
        $table->primary(['variant_id', 'option_id']);
    });

    $schema->create('cmz_attribute_index', static function (Blueprint $table): void {
        $table->increments('id');
        $table->string('product_id', 36);
        $table->string('variant_id', 36)->nullable();
        $table->string('attribute_id', 36);
        $table->string('choice_id', 36)->nullable();
        $table->string('num', 64)->nullable();
        $table->boolean('flag')->nullable();
        $table->string('day', 16)->nullable();
        $table->string('term', 255)->nullable();
    });

    $schema->create('cmz_categories', static function (Blueprint $table): void {
        $table->string('id', 36)->primary();
        $table->string('parent_id', 36)->nullable();
        $table->string('code', 64)->unique();
        $table->text('name');
        $table->integer('position')->default(0);
        $table->string('status', 16)->default('active');
        $table->string('created_at', 40);
        $table->string('updated_at', 40);
    });

    $schema->create('cmz_category_products', static function (Blueprint $table): void {
        $table->string('category_id', 36);
        $table->string('product_id', 36);
        $table->integer('position')->default(0);
        $table->primary(['category_id', 'product_id']);
    });

    $schema->create('cmz_webhook_subscriptions', static function (Blueprint $table): void {
        $table->string('id', 36)->primary();
        $table->string('code', 64)->unique();
        $table->string('url', 2048);
        $table->text('event_types');
        $table->string('secret', 255);
        $table->string('status', 16)->default('active');
        $table->integer('failure_threshold')->default(5);
        $table->integer('consecutive_failures')->default(0);
        $table->string('disabled_at', 40)->nullable();
        $table->string('created_at', 40);
        $table->string('updated_at', 40);
    });

    $schema->create('cmz_listings', static function (Blueprint $table): void {
        $table->string('product_id', 36);
        $table->string('channel_id', 36);
        $table->string('status', 16)->default('published');
        $table->string('available_from', 40)->nullable();
        $table->string('available_until', 40)->nullable();
        $table->string('created_at', 40);
        $table->string('updated_at', 40);
        $table->primary(['product_id', 'channel_id']);
    });
}

/**
 * Seeds options (`color`, `size`), attributes (`material`, `tags`, `weight`, `organic`, `released`, `fit`, `brand`),
 * and an `apparel` product type on the isolated SQLite connection.
 *
 * @return array{type: ProductTypeId}
 */
function seedCatalogSearchFixtures(): array
{
    $db = DB::connection('catalog_search_sqlite');
    $ts = '2026-11-26 12:00:00.000000+00';

    $addOption = static function (string $code, array $values) use ($db, $ts): string {
        $optId = Uuid7::generate();
        $db->table('cmz_options')->insert([
            'id' => $optId,
            'code' => $code,
            'label' => json_encode(['en' => ucfirst($code)], JSON_THROW_ON_ERROR),
            'created_at' => $ts,
            'updated_at' => $ts,
        ]);

        foreach (array_values($values) as $pos => $val) {
            $db->table('cmz_option_values')->insert([
                'id' => Uuid7::generate(),
                'option_id' => $optId,
                'code' => $val,
                'label' => json_encode(['en' => ucfirst($val)], JSON_THROW_ON_ERROR),
                'position' => $pos,
            ]);
        }

        return $optId;
    };

    $addAttr = static function (string $code, string $type, array $config = [], array $choices = [], bool $filterable = true) use ($db, $ts): string {
        $attrId = Uuid7::generate();
        $db->table('cmz_attributes')->insert([
            'id' => $attrId,
            'code' => $code,
            'type' => $type,
            'label' => json_encode(['en' => ucfirst($code)], JSON_THROW_ON_ERROR),
            'config' => json_encode((object) $config, JSON_THROW_ON_ERROR),
            'filterable' => $filterable,
            'status' => 'active',
            'created_at' => $ts,
            'updated_at' => $ts,
        ]);

        foreach (array_values($choices) as $pos => $choice) {
            $db->table('cmz_attribute_choices')->insert([
                'id' => Uuid7::generate(),
                'attribute_id' => $attrId,
                'code' => $choice,
                'label' => json_encode(['en' => ucfirst($choice)], JSON_THROW_ON_ERROR),
                'position' => $pos,
            ]);
        }

        return $attrId;
    };

    $colorOpt = $addOption('color', ['red', 'blue', 'green']);
    $sizeOpt = $addOption('size', ['s', 'm', 'l']);

    $materialAttr = $addAttr('material', 'enum', choices: ['cotton', 'linen', 'wool']);
    $tagsAttr = $addAttr('tags', 'multi_enum', choices: ['eco', 'new', 'sale']);
    $weightAttr = $addAttr('weight', 'decimal', config: ['scale' => 2]);
    $organicAttr = $addAttr('organic', 'boolean');
    $releasedAttr = $addAttr('released', 'date');
    $brandAttr = $addAttr('brand', 'text');
    $fitAttr = $addAttr('fit', 'enum', choices: ['slim', 'regular', 'relaxed']);

    $typeId = Uuid7::generate();
    $db->table('cmz_product_types')->insert([
        'id' => $typeId,
        'code' => 'apparel',
        'kind' => 'physical',
        'label' => json_encode(['en' => 'Apparel'], JSON_THROW_ON_ERROR),
        'status' => 'active',
        'created_at' => $ts,
        'updated_at' => $ts,
    ]);

    foreach ([$materialAttr, $tagsAttr, $weightAttr, $organicAttr, $releasedAttr, $brandAttr] as $pos => $attrId) {
        $db->table('cmz_product_type_attributes')->insert([
            'product_type_id' => $typeId,
            'attribute_id' => $attrId,
            'level' => 'product',
            'required' => false,
            'position' => $pos,
        ]);
    }

    $db->table('cmz_product_type_attributes')->insert([
        'product_type_id' => $typeId,
        'attribute_id' => $fitAttr,
        'level' => 'variant',
        'required' => false,
        'position' => 0,
    ]);

    foreach ([$colorOpt, $sizeOpt] as $pos => $optId) {
        $db->table('cmz_product_type_options')->insert([
            'product_type_id' => $typeId,
            'option_id' => $optId,
            'position' => $pos,
        ]);
    }

    return ['type' => new ProductTypeId($typeId)];
}

beforeEach(function (): void {
    $dbPath = sys_get_temp_dir().'/commerz_catalog_search_'.bin2hex(random_bytes(6)).'_test';
    touch($dbPath);
    $this->sqliteDbPath = $dbPath;

    config([
        'database.connections.catalog_search_sqlite' => [
            'driver' => 'sqlite',
            'database' => $dbPath,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ],
        'commerz.connection' => 'catalog_search_sqlite',
    ]);

    app()->forgetInstance(CommerzOptions::class);
    app()->forgetInstance(TableNames::class);

    $this->clock = new FrozenClock('2026-11-26T12:00:00Z');
    app()->instance(Clock::class, $this->clock);
    app()->instance(Transactions::class, new SqliteCatalogSearchTransactions);

    initCatalogSearchSqliteSchema();
});

afterEach(function (): void {
    DB::purge('catalog_search_sqlite');

    if (isset($this->sqliteDbPath) && is_string($this->sqliteDbPath) && file_exists($this->sqliteDbPath)) {
        @unlink($this->sqliteDbPath);
    }
});

it('computes disjunctive facet counts so selecting color=red does not zero out other colors (CAT-03)', function (): void {
    $type = seedCatalogSearchFixtures()['type'];

    $upserted = Catalog::products()->upsertMany(new UpsertProducts([
        new UpsertProductItem($type, 'shirt-a', LocalizedText::of('en', 'Linen Shirt A'), attributes: [
            'material' => 'linen',
            'tags' => ['eco', 'new'],
            'weight' => '1.50',
            'organic' => true,
            'released' => '2026-01-10',
            'brand' => 'acme',
        ]),
        new UpsertProductItem($type, 'shirt-b', LocalizedText::of('en', 'Cotton Shirt B'), attributes: [
            'material' => 'cotton',
            'tags' => ['new'],
            'weight' => '2.25',
            'organic' => false,
            'released' => '2026-03-15',
            'brand' => 'globex',
        ]),
        new UpsertProductItem($type, 'shirt-c', LocalizedText::of('en', 'Cotton Shirt C'), attributes: [
            'material' => 'cotton',
            'tags' => ['sale'],
            'weight' => '3.75',
            'organic' => true,
            'released' => '2026-06-20',
            'brand' => 'acme',
        ]),
        new UpsertProductItem($type, 'shirt-d', LocalizedText::of('en', 'Wool Shirt D'), attributes: [
            'material' => 'wool',
            'tags' => ['eco'],
            'weight' => '4.00',
            'organic' => true,
            'released' => '2026-09-01',
            'brand' => 'initech',
        ]),
    ]));

    [$a, $b, $c, $d] = $upserted->products;

    Catalog::variants()->upsertMany(new UpsertVariants([
        // A: red/m (slim)
        new UpsertVariantItem($a->id, 'A-RED-M', ['color' => 'red', 'size' => 'm'], attributes: ['fit' => 'slim']),
        // B: red/s (slim) and blue/m (regular) — NOT red/m!
        new UpsertVariantItem($b->id, 'B-RED-S', ['color' => 'red', 'size' => 's'], attributes: ['fit' => 'slim']),
        new UpsertVariantItem($b->id, 'B-BLUE-M', ['color' => 'blue', 'size' => 'm'], attributes: ['fit' => 'regular']),
        // C: green/m (regular) + an archived red/m that must be ignored everywhere
        new UpsertVariantItem($c->id, 'C-GREEN-M', ['color' => 'green', 'size' => 'm'], attributes: ['fit' => 'regular']),
        new UpsertVariantItem($c->id, 'C-RED-M-ARCH', ['color' => 'red', 'size' => 'm'], attributes: ['fit' => 'slim'], status: VariantStatus::Archived),
        // D: blue/s (relaxed)
        new UpsertVariantItem($d->id, 'D-BLUE-S', ['color' => 'blue', 'size' => 's'], attributes: ['fit' => 'relaxed']),
    ]));

    // 1. Selecting color=red alone: hits are A and B, while color facet STILL shows blue=2 and green=1!
    $onlyRed = Catalog::search()->search(new CatalogSearchQuery(
        filters: [VariantFilter::axes(['color' => 'red'])],
        facets: ['color', 'size', 'material', 'tags', 'weight', 'organic', 'released'],
    ));

    expect($onlyRed->total)->toBe(2)
        ->and(array_map(static fn (ProductView $p): string => $p->handle, $onlyRed->items()))->toBe(['shirt-a', 'shirt-b'])
        ->and($onlyRed->facet('color')?->kind)->toBe(FacetKind::Option)
        ->and($onlyRed->facet('color')?->counts())->toBe(['blue' => 2, 'red' => 2, 'green' => 1])
        ->and($onlyRed->facet('color')?->values)->each(function ($entry): void {
            if ($entry->value->value === 'red') {
                expect($entry->value->selected)->toBeTrue();
            } else {
                expect($entry->value->selected)->toBeFalse();
            }
        })
        ->and($onlyRed->facet('size')?->counts())->toBe(['m' => 1, 's' => 1])
        ->and($onlyRed->facet('material')?->counts())->toBe(['cotton' => 1, 'linen' => 1])
        ->and($onlyRed->facet('tags')?->counts())->toBe(['new' => 2, 'eco' => 1])
        ->and($onlyRed->facet('weight')?->min)->toBe('1.50')
        ->and($onlyRed->facet('weight')?->max)->toBe('2.25')
        ->and($onlyRed->facet('organic')?->counts())->toBe(['false' => 1, 'true' => 1])
        ->and($onlyRed->facet('released')?->min)->toBe('2026-01-10')
        ->and($onlyRed->facet('released')?->max)->toBe('2026-03-15');

    // 2. Selecting color=red AND size=m: CAT-03 matches ONLY shirt-a (shirt-b has red/s and blue/m, not red/m!).
    // Disjunctive color facet drops color filter and keeps size=m -> A (red), B (blue), C (green) => red=1, blue=1, green=1!
    // Disjunctive size facet drops size filter and keeps color=red -> A (m), B (s) => m=1, s=1!
    $redMedium = Catalog::search()->search(new CatalogSearchQuery(
        filters: [VariantFilter::axes(['color' => 'red', 'size' => 'm'])],
        facets: ['color', 'size', 'material', 'fit'],
    ));

    expect($redMedium->total)->toBe(1)
        ->and(array_map(static fn (ProductView $p): string => $p->handle, $redMedium->items()))->toBe(['shirt-a'])
        ->and($redMedium->facet('color')?->counts())->toBe(['blue' => 1, 'green' => 1, 'red' => 1])
        ->and($redMedium->facet('size')?->counts())->toBe(['m' => 1, 's' => 1])
        ->and($redMedium->facet('material')?->counts())->toBe(['linen' => 1])
        ->and($redMedium->facet('fit')?->counts())->toBe(['slim' => 1]);
});

it('supports disjunctive and conjunctive attribute facets over cmz_attribute_index', function (): void {
    $type = seedCatalogSearchFixtures()['type'];

    $upserted = Catalog::products()->upsertMany(new UpsertProducts([
        new UpsertProductItem($type, 'p1', LocalizedText::of('en', 'P1'), attributes: ['material' => 'linen', 'brand' => 'acme']),
        new UpsertProductItem($type, 'p2', LocalizedText::of('en', 'P2'), attributes: ['material' => 'cotton', 'brand' => 'acme']),
        new UpsertProductItem($type, 'p3', LocalizedText::of('en', 'P3'), attributes: ['material' => 'cotton', 'brand' => 'globex']),
        new UpsertProductItem($type, 'p4', LocalizedText::of('en', 'P4'), attributes: ['material' => 'wool', 'brand' => 'acme']),
    ]));

    $variants = [];
    foreach ($upserted->products as $idx => $p) {
        $variants[] = new UpsertVariantItem($p->id, "SKU-{$idx}", ['color' => 'red', 'size' => 'm']);
    }
    Catalog::variants()->upsertMany(new UpsertVariants($variants));

    // Disjunctive material facet with material=linen AND brand=acme:
    // Hits: p1 only. Material facet excludes material filter, keeps brand=acme -> cotton=1, linen=1, wool=1!
    $disjunctive = Catalog::search()->search(new CatalogSearchQuery(
        filters: [
            AttributeFilter::equals('material', 'linen'),
            AttributeFilter::equals('brand', 'acme'),
        ],
        facets: [FacetRequest::attribute('material', disjunctive: true)],
    ));

    expect($disjunctive->total)->toBe(1)
        ->and($disjunctive->facet('material')?->counts())->toBe(['cotton' => 1, 'linen' => 1, 'wool' => 1])
        ->and($disjunctive->facet('material')?->values[1]->label?->texts['en'])->toBe('Linen');

    // Conjunctive (non-disjunctive) material facet zeroes out non-selected materials.
    $conjunctive = Catalog::search()->search(new CatalogSearchQuery(
        filters: [
            AttributeFilter::equals('material', 'linen'),
            AttributeFilter::equals('brand', 'acme'),
        ],
        facets: [FacetRequest::attribute('material', disjunctive: false)],
    ));

    expect($conjunctive->facet('material')?->counts())->toBe(['linen' => 1]);
});

it('pages search results across all sort orders without duplicates or gaps', function (): void {
    $type = seedCatalogSearchFixtures()['type'];

    $items = [];
    for ($i = 1; $i <= 15; $i++) {
        $handle = sprintf('item-%02d', $i);
        $name = $i % 3 === 0 ? "Special Jacket {$i}" : "Jacket {$i}";
        $items[] = new UpsertProductItem($type, $handle, LocalizedText::of('en', $name), attributes: ['material' => 'cotton']);
    }

    // All 15 items share the exact same created_at (FrozenClock!), stressing tie-breaking on p.id!
    Catalog::products()->upsertMany(new UpsertProducts($items));

    foreach ([SearchSort::HandleAsc, SearchSort::HandleDesc, SearchSort::Newest, SearchSort::Oldest, SearchSort::Relevance] as $sort) {
        $term = $sort === SearchSort::Relevance ? 'jacket' : null;
        $full = Catalog::search()->search(new CatalogSearchQuery(term: $term, sort: $sort, first: 50));
        $expectedIds = array_map(static fn (ProductView $p): string => $p->id->value, $full->items());

        $pagedIds = [];
        $cursor = null;
        $pages = 0;

        do {
            $res = Catalog::search()->search(new CatalogSearchQuery(term: $term, sort: $sort, first: 4, after: $cursor));
            foreach ($res->items() as $item) {
                $pagedIds[] = $item->id->value;
            }
            $cursor = $res->nextCursor();
            $pages++;
        } while ($cursor !== null && $pages < 20);

        expect($full->total)->toBe(15)
            ->and($pages)->toBe(4)
            ->and(count(array_unique($pagedIds)))->toBe(15)
            ->and($pagedIds)->toBe($expectedIds);
    }
});

it('supports full-text search, category subtree, channel availability window, and outbox sync to external adapters', function (): void {
    $type = seedCatalogSearchFixtures()['type'];
    $db = DB::connection('catalog_search_sqlite');
    $ts = '2026-11-26 12:00:00.000000+00';

    $upserted = Catalog::products()->upsertMany(new UpsertProducts([
        new UpsertProductItem($type, 'merino-sweater', LocalizedText::of('en', 'Merino Wool Sweater'), attributes: ['material' => 'wool']),
        new UpsertProductItem($type, 'merino-scarf', LocalizedText::of('en', 'Warm Scarf'), LocalizedText::of('en', 'Soft merino blend'), attributes: ['material' => 'wool']),
        new UpsertProductItem($type, 'linen-tee', LocalizedText::of('en', 'Summer Tee'), attributes: ['material' => 'linen']),
    ]));

    [$sweater, $scarf, $tee] = $upserted->products;

    Catalog::variants()->upsertMany(new UpsertVariants([
        new UpsertVariantItem($sweater->id, 'SW-001', ['color' => 'red', 'size' => 'm']),
        new UpsertVariantItem($scarf->id, 'SC-001', ['color' => 'blue', 'size' => 's']),
        new UpsertVariantItem($tee->id, 'MERINO-SKU', ['color' => 'green', 'size' => 'l']),
    ]));

    // Category hierarchy: root -> knitwear -> sweaters
    $rootId = Uuid7::generate();
    $knitId = Uuid7::generate();
    $db->table('cmz_categories')->insert([
        ['id' => $rootId, 'parent_id' => null, 'code' => 'root', 'name' => '{"en":"Root"}', 'position' => 0, 'status' => 'active', 'created_at' => $ts, 'updated_at' => $ts],
        ['id' => $knitId, 'parent_id' => $rootId, 'code' => 'knit', 'name' => '{"en":"Knit"}', 'position' => 0, 'status' => 'active', 'created_at' => $ts, 'updated_at' => $ts],
    ]);
    $db->table('cmz_category_products')->insert([
        ['category_id' => $knitId, 'product_id' => $sweater->id->value, 'position' => 0],
        ['category_id' => $rootId, 'product_id' => $scarf->id->value, 'position' => 1],
    ]);

    // Channel availability window tested against FrozenClock (2026-11-26T12:00:00Z).
    $channel = ChannelId::generate();
    $db->table('cmz_listings')->insert([
        ['product_id' => $sweater->id->value, 'channel_id' => $channel->value, 'status' => 'published', 'available_from' => '2026-11-01 00:00:00.000000+00', 'available_until' => '2026-12-01 00:00:00.000000+00', 'created_at' => $ts, 'updated_at' => $ts],
        ['product_id' => $scarf->id->value, 'channel_id' => $channel->value, 'status' => 'published', 'available_from' => '2026-12-01 00:00:00.000000+00', 'available_until' => null, 'created_at' => $ts, 'updated_at' => $ts],
    ]);

    // Full-text relevance: matches handle/name/description/SKU!
    $textSearch = Catalog::search()->search(new CatalogSearchQuery(term: 'merino', sort: SearchSort::Relevance));
    expect($textSearch->total)->toBe(3)
        ->and(array_map(static fn (ProductView $p): string => $p->handle, $textSearch->items()))->toBe(['merino-sweater', 'merino-scarf', 'linen-tee']);

    // Category subtree + channel window.
    $channelSearch = Catalog::search()->search(new CatalogSearchQuery(
        channel: $channel,
        category: new CategoryId($rootId),
    ));
    expect($channelSearch->total)->toBe(1)
        ->and($channelSearch->items()[0]->handle)->toBe('merino-sweater');

    // Outbox subscriber forwards recorded compressed outbox events to a custom external CatalogSearchPort!
    $external = new class implements CatalogSearchPort
    {
        /** @var list<list<string>> */
        public array $indexedBatches = [];

        /** @var list<list<string>> */
        public array $removedBatches = [];

        public function search(CatalogSearchQuery $query): CatalogSearchResult
        {
            return new CatalogSearchResult(new Page([], null), 0);
        }

        public function indexProducts(array $productIds): void
        {
            $this->indexedBatches[] = array_map(static fn (ProductId $id): string => $id->value, $productIds);
        }

        public function removeProducts(array $productIds): void
        {
            $this->removedBatches[] = array_map(static fn (ProductId $id): string => $id->value, $productIds);
        }
    };

    app()->instance(CatalogSearchPort::class, $external);
    app()->forgetInstance(CatalogSearchOutboxSubscriber::class);

    $outboxRows = $db->table('cmz_outbox_messages')->orderBy('id')->get();
    expect($outboxRows)->toHaveCount(2); // 1 products_upserted + 1 variants_upserted!

    foreach ($outboxRows as $row) {
        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) $row->payload, true, 512, JSON_THROW_ON_ERROR);
        app(Dispatcher::class)->dispatch(new IntegrationEventDelivered(new IntegrationEvent(
            (string) $row->event_id,
            (string) $row->type,
            (int) $row->version,
            (string) $row->aggregate_ref,
            $payload,
            new DateTimeImmutable((string) $row->occurred_at),
        )));
    }

    expect($external->indexedBatches)->toHaveCount(2)
        ->and($external->indexedBatches[0])->toBe([$sweater->id->value, $scarf->id->value, $tee->id->value])
        ->and($external->indexedBatches[1])->toHaveCount(3);
});
