<?php

declare(strict_types=1);

use Commerz\Catalog\Attributes\Exceptions\InvalidAttributeValue;
use Commerz\Catalog\Attributes\Exceptions\MissingRequiredAttributes;
use Commerz\Catalog\Common\Exceptions\DuplicateCode;
use Commerz\Catalog\Facades\Catalog;
use Commerz\Catalog\Products\Data\AttributeFilter;
use Commerz\Catalog\Products\Data\UpsertProductItem;
use Commerz\Catalog\Products\Data\UpsertProducts;
use Commerz\Catalog\Products\Enums\ProductStatus;
use Commerz\Catalog\Products\Events\ProductsUpserted;
use Commerz\Catalog\ProductTypes\Data\ProductTypeId;
use Commerz\Catalog\Search\Data\CatalogSearchQuery;
use Commerz\Catalog\Variants\Data\Dimensions;
use Commerz\Catalog\Variants\Data\UpsertVariantItem;
use Commerz\Catalog\Variants\Data\UpsertVariants;
use Commerz\Catalog\Variants\Enums\VariantStatus;
use Commerz\Catalog\Variants\Events\VariantsUpserted;
use Commerz\Catalog\Variants\Exceptions\DuplicateCombination;
use Commerz\Catalog\Variants\Exceptions\InvalidAxes;
use Commerz\Configuration\CommerzOptions;
use Commerz\Identity\Uuid7;
use Commerz\Measures\LocalizedText;
use Commerz\Operations\Contracts\Transactions;
use Commerz\Schema\TableNames;
use Commerz\Testkit\Fakes\FrozenClock;
use Commerz\Time\Contracts\Clock;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;

final class SqliteCatalogBatchTransactions implements Transactions
{
    public function run(callable $work, int $lockTimeoutMs = 3000): mixed
    {
        return DB::connection('catalog_batch_sqlite')->transaction(static fn () => $work());
    }

    public function inTransaction(): bool
    {
        return DB::connection('catalog_batch_sqlite')->transactionLevel() > 0;
    }
}

function initCatalogBatchSchema(): void
{
    $schema = Schema::connection('catalog_batch_sqlite');

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
}

function seedCatalogBatchType(bool $requireMaterial = false): ProductTypeId
{
    $db = DB::connection('catalog_batch_sqlite');
    $ts = '2026-11-26 12:00:00.000000+00';

    $colorOptId = Uuid7::generate();
    $db->table('cmz_options')->insert(['id' => $colorOptId, 'code' => 'color', 'label' => '{"en":"Color"}', 'created_at' => $ts, 'updated_at' => $ts]);
    $colors = ['c1', 'c2', 'c3', 'c4', 'c5', 'c6'];
    foreach ($colors as $pos => $c) {
        $db->table('cmz_option_values')->insert(['id' => Uuid7::generate(), 'option_id' => $colorOptId, 'code' => $c, 'label' => '{"en":"'.$c.'"}', 'position' => $pos]);
    }

    $sizeOptId = Uuid7::generate();
    $db->table('cmz_options')->insert(['id' => $sizeOptId, 'code' => 'size', 'label' => '{"en":"Size"}', 'created_at' => $ts, 'updated_at' => $ts]);
    $sizes = ['xs', 's', 'm', 'l', 'xl'];
    foreach ($sizes as $pos => $s) {
        $db->table('cmz_option_values')->insert(['id' => Uuid7::generate(), 'option_id' => $sizeOptId, 'code' => $s, 'label' => '{"en":"'.$s.'"}', 'position' => $pos]);
    }

    $materialAttrId = Uuid7::generate();
    $db->table('cmz_attributes')->insert([
        'id' => $materialAttrId, 'code' => 'material', 'type' => 'enum', 'label' => '{"en":"Material"}',
        'config' => '{}', 'filterable' => true, 'status' => 'active', 'created_at' => $ts, 'updated_at' => $ts,
    ]);
    foreach (['cotton', 'linen', 'wool'] as $pos => $ch) {
        $db->table('cmz_attribute_choices')->insert(['id' => Uuid7::generate(), 'attribute_id' => $materialAttrId, 'code' => $ch, 'label' => '{"en":"'.$ch.'"}', 'position' => $pos]);
    }

    $brandAttrId = Uuid7::generate();
    $db->table('cmz_attributes')->insert([
        'id' => $brandAttrId, 'code' => 'brand', 'type' => 'text', 'label' => '{"en":"Brand"}',
        'config' => '{}', 'filterable' => true, 'status' => 'active', 'created_at' => $ts, 'updated_at' => $ts,
    ]);

    $gradeAttrId = Uuid7::generate();
    $db->table('cmz_attributes')->insert([
        'id' => $gradeAttrId, 'code' => 'grade', 'type' => 'enum', 'label' => '{"en":"Grade"}',
        'config' => '{}', 'filterable' => true, 'status' => 'active', 'created_at' => $ts, 'updated_at' => $ts,
    ]);
    foreach (['std', 'pro'] as $pos => $ch) {
        $db->table('cmz_attribute_choices')->insert(['id' => Uuid7::generate(), 'attribute_id' => $gradeAttrId, 'code' => $ch, 'label' => '{"en":"'.$ch.'"}', 'position' => $pos]);
    }

    $typeId = Uuid7::generate();
    $db->table('cmz_product_types')->insert([
        'id' => $typeId, 'code' => 'batch_apparel', 'kind' => 'physical', 'label' => '{"en":"Batch Apparel"}',
        'status' => 'active', 'created_at' => $ts, 'updated_at' => $ts,
    ]);

    $db->table('cmz_product_type_attributes')->insert([
        ['product_type_id' => $typeId, 'attribute_id' => $materialAttrId, 'level' => 'product', 'required' => $requireMaterial, 'position' => 0],
        ['product_type_id' => $typeId, 'attribute_id' => $brandAttrId, 'level' => 'product', 'required' => false, 'position' => 1],
        ['product_type_id' => $typeId, 'attribute_id' => $gradeAttrId, 'level' => 'variant', 'required' => false, 'position' => 0],
    ]);

    $db->table('cmz_product_type_options')->insert([
        ['product_type_id' => $typeId, 'option_id' => $colorOptId, 'position' => 0],
        ['product_type_id' => $typeId, 'option_id' => $sizeOptId, 'position' => 1],
    ]);

    return new ProductTypeId($typeId);
}

beforeEach(function (): void {
    $dbPath = sys_get_temp_dir().'/commerz_catalog_batch_'.bin2hex(random_bytes(6)).'_test';
    touch($dbPath);
    $this->sqliteDbPath = $dbPath;

    config([
        'database.connections.catalog_batch_sqlite' => [
            'driver' => 'sqlite',
            'database' => $dbPath,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ],
        'commerz.connection' => 'catalog_batch_sqlite',
    ]);

    app()->forgetInstance(CommerzOptions::class);
    app()->forgetInstance(TableNames::class);

    app()->instance(Clock::class, new FrozenClock('2026-11-26T12:00:00Z'));
    app()->instance(Transactions::class, new SqliteCatalogBatchTransactions);

    initCatalogBatchSchema();
});

afterEach(function (): void {
    DB::purge('catalog_batch_sqlite');

    if (property_exists($this, 'sqliteDbPath') && is_file($this->sqliteDbPath)) {
        @unlink($this->sqliteDbPath);
    }
});

it('validates Products::upsertMany atomically and supports create/update merge, replaceAttributes, and grouped reindex', function (): void {
    $type = seedCatalogBatchType(requireMaterial: true);
    $db = DB::connection('catalog_batch_sqlite');

    // Atomic rejection: missing required attribute leaves zero rows written anywhere.
    expect(fn () => Catalog::products()->upsertMany(new UpsertProducts([
        new UpsertProductItem($type, 'valid-p', LocalizedText::of('en', 'Valid'), attributes: ['material' => 'cotton']),
        new UpsertProductItem($type, 'missing-req', LocalizedText::of('en', 'Bad'), attributes: ['brand' => 'acme']),
    ])))->toThrow(MissingRequiredAttributes::class);

    expect($db->table('cmz_products')->count())->toBe(0)
        ->and($db->table('cmz_attribute_index')->count())->toBe(0)
        ->and($db->table('cmz_outbox_messages')->count())->toBe(0);

    // Create 2 products.
    $dispatched = [];
    Event::listen(ProductsUpserted::class, static function (ProductsUpserted $event) use (&$dispatched): void {
        $dispatched[] = $event;
    });

    $first = Catalog::products()->upsertMany(new UpsertProducts([
        new UpsertProductItem($type, 'tee-1', LocalizedText::of('en', 'Tee 1'), attributes: ['material' => 'cotton', 'brand' => 'acme']),
        new UpsertProductItem($type, 'tee-2', LocalizedText::of('en', 'Tee 2'), attributes: ['material' => 'linen', 'brand' => 'globex']),
    ]));

    expect($first->createdCount)->toBe(2)
        ->and($first->updatedCount)->toBe(0)
        ->and($first->outboxEventCount)->toBe(1)
        ->and($first->indexedFactCount)->toBe(4)
        ->and($first->products[0]->axes)->toBe(['color', 'size'])
        ->and($dispatched)->toHaveCount(1)
        ->and($dispatched[0]->count)->toBe(2);

    // Mixed update + create: tee-1 merges brand (keeps cotton), tee-2 replaces attributes (drops brand, sets wool), tee-3 created.
    $second = Catalog::products()->upsertMany(new UpsertProducts([
        new UpsertProductItem($type, 'tee-1', LocalizedText::of('en', 'Tee 1 Updated'), attributes: ['brand' => 'updated-brand'], replaceAttributes: false),
        new UpsertProductItem($type, 'tee-2', LocalizedText::of('en', 'Tee 2 Updated'), attributes: ['material' => 'wool'], status: ProductStatus::Draft, replaceAttributes: true),
        new UpsertProductItem($type, 'tee-3', LocalizedText::of('en', 'Tee 3'), attributes: ['material' => 'cotton']),
    ]));

    expect($second->createdCount)->toBe(1)
        ->and($second->updatedCount)->toBe(2)
        ->and($second->products[0]->id->value)->toBe($first->products[0]->id->value)
        ->and($second->products[0]->attributes)->toBe(['brand' => 'updated-brand', 'material' => 'cotton'])
        ->and($second->products[1]->id->value)->toBe($first->products[1]->id->value)
        ->and($second->products[1]->status)->toBe(ProductStatus::Draft)
        ->and($second->products[1]->attributes)->toBe(['material' => 'wool'])
        ->and($second->products[2]->handle)->toBe('tee-3')
        ->and($db->table('cmz_attribute_index')->where('product_id', $first->products[1]->id->value)->count())->toBe(1);
});

it('validates Variants::upsertMany atomically and enforces axis immutability and combination uniqueness', function (): void {
    $type = seedCatalogBatchType();
    $db = DB::connection('catalog_batch_sqlite');

    $products = Catalog::products()->upsertMany(new UpsertProducts([
        new UpsertProductItem($type, 'p-alpha', LocalizedText::of('en', 'Alpha')),
        new UpsertProductItem($type, 'p-beta', LocalizedText::of('en', 'Beta')),
    ]))->products;
    [$pA, $pB] = $products;

    // Duplicate combination within same batch rejected before any write.
    expect(fn () => Catalog::variants()->upsertMany(new UpsertVariants([
        new UpsertVariantItem($pA->id, 'SKU-1', ['color' => 'c1', 'size' => 's']),
        new UpsertVariantItem($pA->id, 'SKU-2', ['color' => 'c1', 'size' => 's']),
    ])))->toThrow(DuplicateCombination::class);

    expect($db->table('cmz_variants')->count())->toBe(0);

    // Invalid attribute value in batch rolls back entire batch.
    expect(fn () => Catalog::variants()->upsertMany(new UpsertVariants([
        new UpsertVariantItem($pA->id, 'SKU-1', ['color' => 'c1', 'size' => 's'], attributes: ['grade' => 'std']),
        new UpsertVariantItem($pA->id, 'SKU-2', ['color' => 'c1', 'size' => 'm'], attributes: ['grade' => 'bogus']),
    ])))->toThrow(InvalidAttributeValue::class);

    expect($db->table('cmz_variants')->count())->toBe(0);

    // Successful create + update mix with grouped reindexing.
    $dispatched = [];
    Event::listen(VariantsUpserted::class, static function (VariantsUpserted $event) use (&$dispatched): void {
        $dispatched[] = $event;
    });

    $created = Catalog::variants()->upsertMany(new UpsertVariants([
        new UpsertVariantItem($pA->id, 'SKU-A1', ['color' => 'c1', 'size' => 's'], barcode: 'BC-1', attributes: ['grade' => 'std'], weightGrams: 250, dimensions: new Dimensions(10, 20, 30)),
        new UpsertVariantItem($pB->id, 'SKU-B1', ['color' => 'c2', 'size' => 'm'], barcode: 'BC-2', attributes: ['grade' => 'pro']),
    ]));

    expect($created->createdCount)->toBe(2)
        ->and($created->updatedCount)->toBe(0)
        ->and($created->outboxEventCount)->toBe(1)
        ->and($created->variants[0]->dimensions?->widthMm)->toBe(20)
        ->and($dispatched)->toHaveCount(1);

    // Cannot change axes of existing SKU.
    expect(fn () => Catalog::variants()->upsertMany(new UpsertVariants([
        new UpsertVariantItem($pA->id, 'SKU-A1', ['color' => 'c2', 'size' => 's']),
    ])))->toThrow(InvalidAxes::class);

    // Cannot move existing SKU to a different product.
    expect(fn () => Catalog::variants()->upsertMany(new UpsertVariants([
        new UpsertVariantItem($pB->id, 'SKU-A1', ['color' => 'c1', 'size' => 's']),
    ])))->toThrow(DuplicateCode::class);

    // Update SKU-A1 grade std -> pro and add SKU-A2; verify cmz_attribute_index reflects new grade immediately via search.
    $updated = Catalog::variants()->upsertMany(new UpsertVariants([
        new UpsertVariantItem($pA->id, 'SKU-A1', ['color' => 'c1', 'size' => 's'], barcode: 'BC-1', attributes: ['grade' => 'pro'], status: VariantStatus::Active),
        new UpsertVariantItem($pA->id, 'SKU-A2', ['color' => 'c1', 'size' => 'm'], attributes: ['grade' => 'std']),
    ]));

    expect($updated->createdCount)->toBe(1)
        ->and($updated->updatedCount)->toBe(1)
        ->and($updated->variants[0]->id->value)->toBe($created->variants[0]->id->value);

    $proSearch = Catalog::search()->search(new CatalogSearchQuery(
        filters: [AttributeFilter::equals('grade', 'pro')],
        facets: ['grade'],
    ));
    expect($proSearch->total)->toBe(2)
        ->and($proSearch->facet('grade')?->counts())->toBe(['pro' => 2, 'std' => 1]);
});

it('upserts 1,050 products and 1,200 SKUs within a strictly bounded SQL query budget (zero N+1) and compressed Outbox events', function (): void {
    $type = seedCatalogBatchType();
    $db = DB::connection('catalog_batch_sqlite');

    // --- Benchmark 1: 1,050 products ---
    $productItems = [];
    for ($i = 1; $i <= 1050; $i++) {
        $productItems[] = new UpsertProductItem(
            type: $type,
            handle: sprintf('bulk-product-%04d', $i),
            name: LocalizedText::of('en', "Bulk Product {$i}"),
            attributes: [
                'material' => ['cotton', 'linen', 'wool'][$i % 3],
                'brand' => 'brand-'.($i % 10),
            ],
        );
    }

    $db->flushQueryLog();
    $db->enableQueryLog();

    $productResult = Catalog::products()->upsertMany(new UpsertProducts($productItems));
    $productInsertQueries = count($db->getQueryLog());

    expect($productResult->createdCount)->toBe(1050)
        ->and($productResult->updatedCount)->toBe(0)
        ->and($productResult->indexedFactCount)->toBe(2100)
        ->and($productResult->outboxEventCount)->toBe(1)
        ->and($productInsertQueries)->toBeLessThanOrEqual(40)
        ->and($db->table('cmz_outbox_messages')->where('type', 'catalog.products_upserted')->count())->toBe(1);

    // Re-upsert all 1,050 products updating attributes (verify ON CONFLICT DO UPDATE + bounded queries).
    $updatedProductItems = [];
    for ($i = 1; $i <= 1050; $i++) {
        $updatedProductItems[] = new UpsertProductItem(
            type: $type,
            handle: sprintf('bulk-product-%04d', $i),
            name: LocalizedText::of('en', "Bulk Product {$i} v2"),
            attributes: ['material' => 'wool'],
        );
    }

    $db->flushQueryLog();
    $productUpdateResult = Catalog::products()->upsertMany(new UpsertProducts($updatedProductItems));
    $productUpdateQueries = count($db->getQueryLog());

    expect($productUpdateResult->createdCount)->toBe(0)
        ->and($productUpdateResult->updatedCount)->toBe(1050)
        ->and($productUpdateResult->outboxEventCount)->toBe(1)
        ->and($productUpdateQueries)->toBeLessThanOrEqual(40);

    // --- Benchmark 2: 1,200 SKUs across 40 products (6 colors x 5 sizes = 30 variants per product) ---
    $colors = ['c1', 'c2', 'c3', 'c4', 'c5', 'c6'];
    $sizes = ['xs', 's', 'm', 'l', 'xl'];
    $variantItems = [];
    $targetProducts = array_slice($productResult->products, 0, 40);

    foreach ($targetProducts as $pIdx => $product) {
        foreach ($colors as $cIdx => $color) {
            foreach ($sizes as $sIdx => $size) {
                $variantItems[] = new UpsertVariantItem(
                    product: $product->id,
                    sku: sprintf('BULK-P%02d-%s-%s', $pIdx + 1, strtoupper($color), strtoupper($size)),
                    axes: ['color' => $color, 'size' => $size],
                    attributes: ['grade' => ($cIdx + $sIdx) % 2 === 0 ? 'std' : 'pro'],
                    weightGrams: 100 + $sIdx * 20,
                );
            }
        }
    }

    expect($variantItems)->toHaveCount(1200);

    $db->flushQueryLog();
    $variantResult = Catalog::variants()->upsertMany(new UpsertVariants($variantItems));
    $variantInsertQueries = count($db->getQueryLog());

    expect($variantResult->createdCount)->toBe(1200)
        ->and($variantResult->updatedCount)->toBe(0)
        ->and($variantResult->indexedFactCount)->toBe(1200)
        ->and($variantResult->outboxEventCount)->toBe(1)
        ->and($variantInsertQueries)->toBeLessThanOrEqual(45)
        ->and($db->table('cmz_outbox_messages')->where('type', 'catalog.variants_upserted')->count())->toBe(1);

    // Re-upsert all 1,200 SKUs updating variant attributes in bulk (bounded query budget!).
    $updatedVariantItems = array_map(
        static fn (UpsertVariantItem $item): UpsertVariantItem => new UpsertVariantItem(
            product: $item->product,
            sku: $item->sku,
            axes: $item->axes,
            attributes: ['grade' => 'pro'],
            weightGrams: 250,
        ),
        $variantItems,
    );

    $db->flushQueryLog();
    $variantUpdateResult = Catalog::variants()->upsertMany(new UpsertVariants($updatedVariantItems));
    $variantUpdateQueries = count($db->getQueryLog());
    $db->disableQueryLog();

    expect($variantUpdateResult->createdCount)->toBe(0)
        ->and($variantUpdateResult->updatedCount)->toBe(1200)
        ->and($variantUpdateResult->indexedFactCount)->toBe(1200)
        ->and($variantUpdateResult->outboxEventCount)->toBe(1)
        ->and($variantUpdateQueries)->toBeLessThanOrEqual(45)
        ->and($db->table('cmz_attribute_index')->whereNotNull('variant_id')->count())->toBe(1200);
});
