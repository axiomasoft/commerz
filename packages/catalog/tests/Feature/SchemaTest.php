<?php

declare(strict_types=1);

use Commerz\Catalog\CatalogServiceProvider;
use Commerz\Identity\Uuid7;
use Commerz\Schema\ConstraintNames;
use Commerz\Schema\Contracts\SchemaOwnership;
use Commerz\Schema\TableNames;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

const CATALOG_TABLES = [
    'attributes', 'attribute_choices', 'options', 'option_values', 'product_types', 'product_type_attributes',
    'product_type_options', 'products', 'product_options', 'variants', 'variant_option_values', 'attribute_index',
    'categories', 'category_products', 'listings',
];

function catalogTable(string $logical): string
{
    return app(TableNames::class)->table($logical);
}

/** @return list<string> */
function catalogConstraints(string $logical): array
{
    return array_map(
        static fn (object $row): string => $row->conname,
        DB::select('select conname from pg_constraint where conrelid = ?::regclass order by conname', [catalogTable($logical)]),
    );
}

// RefreshDatabase wraps each test in a transaction; a savepoint keeps it usable after an expected violation.

/** @param  array<string, mixed>  $row */
function catalogInsert(string $logical, array $row): void
{
    DB::transaction(static fn () => DB::table(catalogTable($logical))->insert($row));
}

/** @param  array<string, mixed>  $set */
function catalogUpdate(string $logical, string $id, array $set): void
{
    DB::transaction(static fn () => DB::table(catalogTable($logical))->where('id', $id)->update($set));
}

function catalogDelete(string $logical, string $id): void
{
    DB::transaction(static fn () => DB::table(catalogTable($logical))->where('id', $id)->delete());
}

/** @return array{type: string, product: string, color: string, red: string, blue: string, size: string, small: string, variant: string} */
function catalogSeed(): array
{
    $ids = [];

    foreach (['type', 'product', 'color', 'red', 'blue', 'size', 'small', 'variant'] as $name) {
        $ids[$name] = Uuid7::generate();
    }

    $name = json_encode(['en' => 'x']);

    catalogInsert('product_types', ['id' => $ids['type'], 'code' => 'shirt', 'name' => $name, 'kind' => 'physical']);
    catalogInsert('products', ['id' => $ids['product'], 'product_type_id' => $ids['type'], 'handle' => 'tee', 'name' => $name]);
    catalogInsert('options', ['id' => $ids['color'], 'code' => 'color', 'name' => $name]);
    catalogInsert('options', ['id' => $ids['size'], 'code' => 'size', 'name' => $name]);
    catalogInsert('option_values', ['id' => $ids['red'], 'option_id' => $ids['color'], 'code' => 'red', 'label' => $name]);
    catalogInsert('option_values', ['id' => $ids['blue'], 'option_id' => $ids['color'], 'code' => 'blue', 'label' => $name]);
    catalogInsert('option_values', ['id' => $ids['small'], 'option_id' => $ids['size'], 'code' => 's', 'label' => $name]);
    catalogInsert('product_options', ['product_id' => $ids['product'], 'option_id' => $ids['color']]);
    catalogInsert('variants', ['id' => $ids['variant'], 'product_id' => $ids['product'], 'sku' => 'TEE-RED', 'option_signature' => $ids['red']]);

    return $ids;
}

/** @return array<string, mixed> */
function catalogVariant(string $productId, string $sku, string $signature, array $extra = []): array
{
    return ['id' => Uuid7::generate(), 'product_id' => $productId, 'sku' => $sku, 'option_signature' => $signature, ...$extra];
}

it('declares the catalog nouns under commerz.catalog', function (): void {
    $owners = app(SchemaOwnership::class)->owners();

    expect($owners['commerz.catalog'])->toBe(CatalogServiceProvider::NOUNS)
        ->and(app(SchemaOwnership::class)->ownerOf('attribute_index'))->toBe('commerz.catalog')
        ->and(app(SchemaOwnership::class)->ownerOf('variant_option_values'))->toBe('commerz.catalog');
});

it('creates every table with canonical constraint names', function (): void {
    foreach (CATALOG_TABLES as $logical) {
        $physical = catalogTable($logical);

        expect(DB::getSchemaBuilder()->hasTable($physical))->toBeTrue("table {$physical}");

        foreach (catalogConstraints($logical) as $name) {
            expect($name)->toMatch('/_(pk|uq|fk|ck)\z/');
        }

        foreach (DB::select('select indexname from pg_indexes where tablename = ?', [$physical]) as $index) {
            expect($index->indexname)->toMatch('/_(pk|uq|ix)\z/');
        }

        expect(catalogConstraints($logical))->toContain(ConstraintNames::primary($physical));
    }
});

it('names the constraints that protect CAT-01', function (): void {
    expect(catalogConstraints('variants'))->toBe([
        'cmz_variants_attributes_object_ck',
        'cmz_variants_barcode_uq',
        'cmz_variants_dimensions_non_negative_ck',
        'cmz_variants_id_product_id_uq',
        'cmz_variants_pk',
        'cmz_variants_product_id_fk',
        'cmz_variants_product_id_option_signature_uq',
        'cmz_variants_sku_uq',
        'cmz_variants_status_valid_ck',
    ]);

    expect(catalogConstraints('variant_option_values'))->toContain(
        'cmz_variant_option_values_pk',
        'cmz_variant_option_values_variant_id_product_id_fk',
        'cmz_variant_option_values_product_id_option_id_fk',
        'cmz_variant_option_values_option_value_id_option_id_fk',
    );
});

it('refuses a second variant with the same option signature and sku or barcode duplicates', function (): void {
    $ids = catalogSeed();

    expect(fn () => catalogInsert('variants', catalogVariant($ids['product'], 'TEE-OTHER', $ids['red'])))
        ->toThrow(UniqueConstraintViolationException::class, 'cmz_variants_product_id_option_signature_uq');

    expect(fn () => catalogInsert('variants', catalogVariant($ids['product'], 'TEE-RED', $ids['blue'])))
        ->toThrow(UniqueConstraintViolationException::class, 'cmz_variants_sku_uq');

    catalogInsert('variants', catalogVariant($ids['product'], 'TEE-BLUE', $ids['blue'], ['barcode' => '4600000000001']));
    catalogInsert('variants', catalogVariant($ids['product'], 'TEE-NONE', 'x1'));
    catalogInsert('variants', catalogVariant($ids['product'], 'TEE-NONE2', 'x2'));

    expect(fn () => catalogInsert('variants', catalogVariant($ids['product'], 'TEE-GREEN', 'x3', ['barcode' => '4600000000001'])))
        ->toThrow(UniqueConstraintViolationException::class, 'cmz_variants_barcode_uq');
});

it('allows the same signature in different products and a simple variant with an empty signature', function (): void {
    $ids = catalogSeed();
    $other = Uuid7::generate();

    catalogInsert('products', ['id' => $other, 'product_type_id' => $ids['type'], 'handle' => 'mug', 'name' => json_encode(['en' => 'm'])]);
    catalogInsert('variants', catalogVariant($other, 'MUG-1', $ids['red']));
    catalogInsert('variants', catalogVariant($other, 'MUG-2', ''));

    expect(DB::table(catalogTable('variants'))->where('product_id', $other)->count())->toBe(2);
});

it('binds a variant option value to an axis of its own product and to the value of that option', function (): void {
    $ids = catalogSeed();
    $row = static fn (string $option, string $value, ?string $variant = null): array => [
        'variant_id' => $variant ?? $ids['variant'], 'product_id' => $ids['product'], 'option_id' => $option, 'option_value_id' => $value,
    ];
    $blueVariant = catalogVariant($ids['product'], 'TEE-BLUE', $ids['blue']);

    catalogInsert('variants', $blueVariant);
    catalogInsert('variant_option_values', $row($ids['color'], $ids['red']));

    expect(fn () => catalogInsert('variant_option_values', $row($ids['size'], $ids['small'])))
        ->toThrow(QueryException::class, 'cmz_variant_option_values_product_id_option_id_fk')
        ->and(fn () => catalogInsert('variant_option_values', $row($ids['color'], $ids['small'], $blueVariant['id'])))
        ->toThrow(QueryException::class, 'cmz_variant_option_values_option_value_id_option_id_fk');

    $foreign = Uuid7::generate();
    catalogInsert('products', ['id' => $foreign, 'product_type_id' => $ids['type'], 'handle' => 'cap', 'name' => json_encode(['en' => 'c'])]);

    catalogInsert('product_options', ['product_id' => $foreign, 'option_id' => $ids['color']]);

    expect(fn () => catalogInsert('variant_option_values', [
        'variant_id' => $blueVariant['id'], 'product_id' => $foreign, 'option_id' => $ids['color'], 'option_value_id' => $ids['blue'],
    ]))->toThrow(QueryException::class, 'cmz_variant_option_values_variant_id_product_id_fk');
});

it('removes variant option values with their variant', function (): void {
    $ids = catalogSeed();

    catalogInsert('variant_option_values', [
        'variant_id' => $ids['variant'], 'product_id' => $ids['product'], 'option_id' => $ids['color'], 'option_value_id' => $ids['red'],
    ]);
    catalogDelete('variants', $ids['variant']);

    expect(DB::table(catalogTable('variant_option_values'))->count())->toBe(0);
});

it('checks statuses, dimensions and attribute documents', function (): void {
    $ids = catalogSeed();

    expect(fn () => catalogUpdate('products', $ids['product'], ['status' => 'deleted']))
        ->toThrow(QueryException::class, 'cmz_products_status_valid_ck')
        ->and(fn () => catalogUpdate('variants', $ids['variant'], ['status' => 'draft']))
        ->toThrow(QueryException::class, 'cmz_variants_status_valid_ck')
        ->and(fn () => catalogUpdate('variants', $ids['variant'], ['weight_grams' => -1]))
        ->toThrow(QueryException::class, 'cmz_variants_dimensions_non_negative_ck')
        ->and(fn () => catalogUpdate('variants', $ids['variant'], ['height_mm' => -5]))
        ->toThrow(QueryException::class, 'cmz_variants_dimensions_non_negative_ck')
        ->and(fn () => catalogUpdate('products', $ids['product'], ['attributes' => '[]']))
        ->toThrow(QueryException::class, 'cmz_products_attributes_object_ck')
        ->and(fn () => catalogUpdate('variants', $ids['variant'], ['attributes' => '"x"']))
        ->toThrow(QueryException::class, 'cmz_variants_attributes_object_ck');

    catalogUpdate('variants', $ids['variant'], ['weight_grams' => 0, 'attributes' => '{"k":1}']);

    $defaults = DB::table(catalogTable('products'))->where('id', $ids['product'])->first();
    expect(jsonb_typeof_of($defaults->attributes))->toBe('object');
});

function jsonb_typeof_of(string $json): string
{
    return DB::selectOne('select jsonb_typeof(?::jsonb) as t', [$json])->t;
}

it('checks attribute and product type enums', function (): void {
    $id = Uuid7::generate();
    $name = json_encode(['en' => 'x']);

    expect(fn () => catalogInsert('attributes', ['id' => $id, 'code' => 'a', 'type' => 'text', 'name' => $name, 'status' => 'gone']))
        ->toThrow(QueryException::class, 'cmz_attributes_status_valid_ck')
        ->and(fn () => catalogInsert('attributes', ['id' => $id, 'code' => 'a', 'type' => 'text', 'name' => $name, 'config' => '[]']))
        ->toThrow(QueryException::class, 'cmz_attributes_config_object_ck');

    catalogInsert('attributes', ['id' => $id, 'code' => 'a', 'type' => 'text', 'name' => $name]);
    $row = DB::table(catalogTable('attributes'))->first();

    expect(jsonb_typeof_of($row->config))->toBe('object')
        ->and($row->config_version)->toBe(1)
        ->and($row->status)->toBe('active')
        ->and($row->filterable)->toBeFalse();

    $type = catalogSeed()['type'];

    expect(fn () => catalogInsert('product_type_attributes', ['product_type_id' => $type, 'attribute_id' => $id, 'level' => 'shop']))
        ->toThrow(QueryException::class, 'cmz_product_type_attributes_level_valid_ck');

    catalogInsert('product_type_attributes', ['product_type_id' => $type, 'attribute_id' => $id, 'level' => 'variant']);

    expect(fn () => catalogInsert('product_type_attributes', ['product_type_id' => $type, 'attribute_id' => $id, 'level' => 'product']))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('keeps exactly one value per attribute_index row and a choice of its own attribute', function (): void {
    $ids = catalogSeed();
    $color = Uuid7::generate();
    $other = Uuid7::generate();
    $choice = Uuid7::generate();
    $name = json_encode(['en' => 'x']);

    catalogInsert('attributes', ['id' => $color, 'code' => 'finish', 'type' => 'choice', 'name' => $name]);
    catalogInsert('attributes', ['id' => $other, 'code' => 'weight', 'type' => 'decimal', 'name' => $name]);
    catalogInsert('attribute_choices', ['id' => $choice, 'attribute_id' => $color, 'code' => 'matte', 'label' => $name]);

    $row = ['product_id' => $ids['product'], 'variant_id' => null, 'attribute_id' => $color];

    expect(fn () => catalogInsert('attribute_index', $row))
        ->toThrow(QueryException::class, 'cmz_attribute_index_one_value_ck')
        ->and(fn () => catalogInsert('attribute_index', [...$row, 'choice_id' => $choice, 'flag' => true]))
        ->toThrow(QueryException::class, 'cmz_attribute_index_one_value_ck')
        ->and(fn () => catalogInsert('attribute_index', [...$row, 'num' => '1.5', 'term' => 'x']))
        ->toThrow(QueryException::class, 'cmz_attribute_index_one_value_ck')
        ->and(fn () => catalogInsert('attribute_index', ['product_id' => $ids['product'], 'attribute_id' => $other, 'choice_id' => $choice]))
        ->toThrow(QueryException::class, 'cmz_attribute_index_choice_id_attribute_id_fk');

    catalogInsert('attribute_index', [...$row, 'choice_id' => $choice]);
    catalogInsert('attribute_index', ['product_id' => $ids['product'], 'variant_id' => $ids['variant'], 'attribute_id' => $other, 'num' => '1234567890123456789.123456789012']);
    catalogInsert('attribute_index', ['product_id' => $ids['product'], 'attribute_id' => $other, 'flag' => false]);
    catalogInsert('attribute_index', ['product_id' => $ids['product'], 'attribute_id' => $other, 'day' => '2026-10-09']);
    catalogInsert('attribute_index', ['product_id' => $ids['product'], 'attribute_id' => $other, 'term' => 'matte']);

    $ids = DB::table(catalogTable('attribute_index'))->orderBy('id')->pluck('id')->all();

    expect($ids)->toBe(array_values(array_unique($ids)))->and($ids)->toHaveCount(5);
});

it('deletes projection rows with their product or variant', function (): void {
    $ids = catalogSeed();
    $attribute = Uuid7::generate();

    catalogInsert('attributes', ['id' => $attribute, 'code' => 'w', 'type' => 'decimal', 'name' => json_encode(['en' => 'x'])]);
    catalogInsert('attribute_index', ['product_id' => $ids['product'], 'variant_id' => $ids['variant'], 'attribute_id' => $attribute, 'num' => '1']);
    catalogDelete('variants', $ids['variant']);

    expect(DB::table(catalogTable('attribute_index'))->count())->toBe(0);
});

it('restricts deletes that would orphan definitions', function (): void {
    $ids = catalogSeed();

    expect(fn () => catalogDelete('product_types', $ids['type']))->toThrow(QueryException::class)
        ->and(fn () => catalogDelete('option_values', $ids['blue']))->not->toThrow(QueryException::class)
        ->and(fn () => catalogDelete('products', $ids['product']))->toThrow(QueryException::class);
});

it('keeps categories a forest of distinct codes and refuses a category as its own parent', function (): void {
    $root = Uuid7::generate();
    $child = Uuid7::generate();
    $name = json_encode(['en' => 'x']);

    catalogInsert('categories', ['id' => $root, 'code' => 'root', 'name' => $name]);
    catalogInsert('categories', ['id' => $child, 'parent_id' => $root, 'code' => 'child', 'name' => $name]);

    expect(fn () => catalogInsert('categories', ['id' => Uuid7::generate(), 'code' => 'root', 'name' => $name]))
        ->toThrow(UniqueConstraintViolationException::class, 'cmz_categories_code_uq')
        ->and(fn () => catalogInsert('categories', ['id' => Uuid7::generate(), 'parent_id' => Uuid7::generate(), 'code' => 'orphan', 'name' => $name]))
        ->toThrow(QueryException::class, 'cmz_categories_parent_id_fk')
        ->and(fn () => catalogUpdate('categories', $root, ['parent_id' => $root]))
        ->toThrow(QueryException::class, 'cmz_categories_not_own_parent_ck')
        ->and(fn () => catalogDelete('categories', $root))
        ->toThrow(QueryException::class, 'cmz_categories_parent_id_fk');
});

it('links products to categories once', function (): void {
    $ids = catalogSeed();
    $category = Uuid7::generate();

    catalogInsert('categories', ['id' => $category, 'code' => 'tops', 'name' => json_encode(['en' => 'x'])]);
    catalogInsert('category_products', ['category_id' => $category, 'product_id' => $ids['product']]);

    expect(fn () => catalogInsert('category_products', ['category_id' => $category, 'product_id' => $ids['product']]))
        ->toThrow(UniqueConstraintViolationException::class, 'cmz_category_products_pk');
});

it('keeps one listing per product and channel with a sane window and no channel foreign key', function (): void {
    $ids = catalogSeed();
    $channel = Uuid7::generate(); // not a real channel: listings carry no foreign key to the core table

    catalogInsert('listings', ['product_id' => $ids['product'], 'channel_id' => $channel, 'status' => 'published']);

    expect(fn () => catalogInsert('listings', ['product_id' => $ids['product'], 'channel_id' => $channel, 'status' => 'published']))
        ->toThrow(UniqueConstraintViolationException::class, 'cmz_listings_pk')
        ->and(fn () => catalogInsert('listings', ['product_id' => $ids['product'], 'channel_id' => Uuid7::generate(), 'status' => 'live']))
        ->toThrow(QueryException::class, 'cmz_listings_status_valid_ck')
        ->and(fn () => catalogInsert('listings', [
            'product_id' => $ids['product'], 'channel_id' => Uuid7::generate(), 'status' => 'published',
            'available_from' => '2026-10-10 00:00:00+00', 'available_until' => '2026-10-10 00:00:00+00',
        ]))->toThrow(QueryException::class, 'cmz_listings_window_ck');

    catalogInsert('listings', [
        'product_id' => $ids['product'], 'channel_id' => Uuid7::generate(), 'status' => 'unpublished',
        'available_from' => '2026-10-10 00:00:00+00', 'available_until' => '2026-10-11 00:00:00+00',
    ]);
    catalogInsert('listings', ['product_id' => $ids['product'], 'channel_id' => Uuid7::generate(), 'status' => 'published', 'available_until' => '2026-10-11 00:00:00+00']);

    $catalogForeignKeys = DB::select(
        "select conname from pg_constraint where conrelid = ?::regclass and contype = 'f' order by conname",
        [catalogTable('listings')],
    );

    expect(array_column($catalogForeignKeys, 'conname'))->toBe(['cmz_listings_product_id_fk']);
});

it('drops and recreates the whole catalog schema on rollback and migrate', function (): void {
    $path = realpath(__DIR__.'/../../database/migrations');
    $options = ['--path' => $path, '--realpath' => true];

    $this->artisan('migrate:rollback', $options)->assertExitCode(0);

    foreach (CATALOG_TABLES as $logical) {
        expect(DB::getSchemaBuilder()->hasTable(catalogTable($logical)))->toBeFalse($logical);
    }

    expect(DB::getSchemaBuilder()->hasTable(catalogTable('stores')))->toBeTrue();

    $this->artisan('migrate', $options)->assertExitCode(0);

    foreach (CATALOG_TABLES as $logical) {
        expect(DB::getSchemaBuilder()->hasTable(catalogTable($logical)))->toBeTrue($logical);
    }
});

it('widens an installed numeric projection without changing existing values and refuses a lossy rollback', function (): void {
    $migration = require __DIR__.'/../../database/migrations/2026_10_09_100009_widen_commerz_attribute_numbers.php';
    $migration->down();
    $ids = catalogSeed();
    $attribute = Uuid7::generate();
    catalogInsert('attributes', ['id' => $attribute, 'code' => 'number', 'type' => 'decimal', 'name' => '{"en":"Number"}']);
    catalogInsert('attribute_index', ['product_id' => $ids['product'], 'attribute_id' => $attribute, 'num' => '123.123456789012']);
    $migration->up();

    expect(DB::table(catalogTable('attribute_index'))->value('num'))->toBe('123.123456789012');
    DB::table(catalogTable('attribute_index'))->update(['num' => '123.1234567890123']);
    expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(LogicException::class, 'without losing precision')
        ->and(DB::table(catalogTable('attribute_index'))->value('num'))->toBe('123.1234567890123');
});

it('refuses an int64 narrowing rollback without changing a stored measurement', function (): void {
    $ids = catalogSeed();
    DB::table(catalogTable('variants'))->where('id', $ids['variant'])->update(['weight_grams' => PHP_INT_MAX]);
    $migration = require __DIR__.'/../../database/migrations/2026_10_09_100010_widen_commerz_catalog_integers.php';
    expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class)
        ->and(DB::table(catalogTable('variants'))->where('id', $ids['variant'])->value('weight_grams'))->toBe(PHP_INT_MAX);
});
