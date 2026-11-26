<?php

declare(strict_types=1);

namespace Commerz\Catalog\Tests\Support;

use Commerz\Catalog\Attributes\Contracts\Attributes;
use Commerz\Catalog\Attributes\Internal\AttributeIndexer;
use Commerz\Catalog\Attributes\Internal\AttributeOwner;
use Commerz\Catalog\Attributes\Internal\AttributeWriter;
use Commerz\Catalog\Catalog;
use Commerz\Catalog\Categories\Contracts\Categories;
use Commerz\Catalog\Collections\Contracts\Collections;
use Commerz\Catalog\Common\Internal\Documents;
use Commerz\Catalog\Listings\Contracts\Listings;
use Commerz\Catalog\Options\Contracts\Options;
use Commerz\Catalog\Products\Contracts\Products;
use Commerz\Catalog\Products\Data\CreateProduct;
use Commerz\Catalog\Products\Data\ProductId;
use Commerz\Catalog\Products\Data\ProductView;
use Commerz\Catalog\Products\Internal\FilterCompiler;
use Commerz\Catalog\Products\Internal\ProductQuery;
use Commerz\Catalog\ProductTypes\Contracts\ProductTypes;
use Commerz\Catalog\Projection\Contracts\StorefrontProjection;
use Commerz\Catalog\Projection\Internal\DatabaseStorefrontProjection;
use Commerz\Catalog\Projection\Internal\StorefrontOutboxProjector;
use Commerz\Catalog\Variants\Contracts\Variants;
use Commerz\Configuration\CommerzOptions;
use Commerz\Identity\Uuid7;
use Commerz\Measures\LocalizedText;
use Commerz\Operations\Contracts\Transactions;
use Commerz\Outbox\Contracts\Outbox;
use Commerz\Schema\TableNames;
use Commerz\Stores\Contracts\Channels;
use Commerz\Stores\Contracts\Stores;
use Commerz\Testkit\Fakes\FrozenClock;
use Commerz\Time\Contracts\Clock;
use Commerz\Time\PgTimestamp;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;

final class CatalogEngineSqliteHarness
{
    private const array BOOL_COLUMNS = ['filterable', 'required', 'prices_include_tax', 'in_stock', 'flag'];

    public static function boot(string $now = '2026-11-26T12:00:00Z'): array
    {
        $dbPath = sys_get_temp_dir().'/commerz_catalog_engine_'.bin2hex(random_bytes(6)).'_test';
        touch($dbPath);

        Connection::resolverFor('sqlite', static function (PDO|\Closure $connection, string $database = '', string $prefix = '', array $config = []): SQLiteConnection {
            if (($config['boolean_emulation'] ?? false) !== true) {
                return new SQLiteConnection($connection, $database, $prefix, $config);
            }

            return new class($connection, $database, $prefix, $config) extends SQLiteConnection
            {
                public function select($query, $bindings = [], $useReadPdo = true, array $fetchUsing = []): array
                {
                    $rows = parent::select($query, $bindings, $useReadPdo, $fetchUsing);

                    foreach ($rows as $row) {
                        if (! is_object($row)) {
                            continue;
                        }

                        foreach (['filterable', 'required', 'prices_include_tax', 'in_stock', 'flag'] as $col) {
                            if (property_exists($row, $col) && is_int($row->{$col})) {
                                $row->{$col} = $row->{$col} !== 0;
                            }
                        }
                    }

                    return $rows;
                }
            };
        });

        config([
            'database.connections.catalog_sqlite' => [
                'driver' => 'sqlite',
                'database' => $dbPath,
                'prefix' => '',
                'foreign_key_constraints' => true,
                'boolean_emulation' => true,
            ],
            'commerz.connection' => 'catalog_sqlite',
        ]);

        $clock = new FrozenClock($now);
        app()->instance(Clock::class, $clock);
        app()->instance(Transactions::class, new class implements Transactions
        {
            public function run(callable $work, int $lockTimeoutMs = 3000): mixed
            {
                return DB::connection('catalog_sqlite')->transaction(static fn () => $work());
            }

            public function inTransaction(): bool
            {
                return DB::connection('catalog_sqlite')->transactionLevel() > 0;
            }
        });

        foreach ([
            CommerzOptions::class,
            TableNames::class,
            Stores::class,
            Channels::class,
            Outbox::class,
            AttributeWriter::class,
            AttributeIndexer::class,
            Attributes::class,
            Options::class,
            ProductTypes::class,
            FilterCompiler::class,
            ProductQuery::class,
            Products::class,
            Variants::class,
            Categories::class,
            Collections::class,
            Listings::class,
            DatabaseStorefrontProjection::class,
            StorefrontProjection::class,
            StorefrontOutboxProjector::class,
            Catalog::class,
        ] as $service) {
            app()->forgetInstance($service);
        }

        self::createTables();

        return ['dbPath' => $dbPath, 'clock' => $clock];
    }

    public static function cleanup(string $dbPath): void
    {
        DB::purge('catalog_sqlite');
        if (is_file($dbPath)) {
            @unlink($dbPath);
        }
    }

    /**
     * Inserts a product using the real {@see AttributeWriter} without PostgreSQL's `?::uuid` cast in `insertUsing`.
     */
    public static function createProduct(CreateProduct $command): ProductView
    {
        return app(Transactions::class)->run(function () use ($command): ProductView {
            $db = DB::connection('catalog_sqlite');
            $writer = app(AttributeWriter::class);
            $id = new ProductId(Uuid7::generate());
            $now = PgTimestamp::now();

            $prepared = $writer->prepare(
                AttributeOwner::product($id),
                $command->type,
                [],
                $command->attributes,
                [],
                creating: true,
            );

            $db->table('cmz_products')->insert([
                'id' => $id->value,
                'product_type_id' => $command->type->value,
                'handle' => $command->handle,
                'name' => Documents::encode($command->name->texts),
                'description' => $command->description instanceof LocalizedText ? Documents::encode($command->description->texts) : null,
                'status' => $command->status->value,
                'attributes' => '{}',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $axes = $db->table('cmz_product_type_options')
                ->where('product_type_id', $command->type->value)
                ->orderBy('position')
                ->get(['option_id', 'position']);

            foreach ($axes as $axis) {
                $db->table('cmz_product_options')->insert([
                    'product_id' => $id->value,
                    'option_id' => $axis->option_id,
                    'position' => $axis->position,
                ]);
            }

            $writer->apply($prepared);

            return app(Products::class)->find($id);
        });
    }

    private static function createTables(): void
    {
        $schema = Schema::connection('catalog_sqlite');

        $schema->create('cmz_stores', static function (Blueprint $t): void {
            $t->string('id', 36)->primary();
            $t->string('code', 64)->unique();
            $t->string('name', 191);
            $t->string('default_currency', 3);
            $t->string('order_number_prefix', 16)->default('');
            $t->string('status', 16)->default('active');
            $t->string('created_at', 64);
            $t->string('updated_at', 64);
        });

        $schema->create('cmz_channels', static function (Blueprint $t): void {
            $t->string('id', 36)->primary();
            $t->string('store_id', 36);
            $t->string('code', 64)->unique();
            $t->string('name', 191);
            $t->text('currencies');
            $t->string('default_currency', 3);
            $t->text('locales');
            $t->string('default_locale', 16);
            $t->boolean('prices_include_tax')->default(true);
            $t->string('status', 16)->default('active');
            $t->string('created_at', 64);
            $t->string('updated_at', 64);
        });

        $schema->create('cmz_outbox_messages', static function (Blueprint $t): void {
            $t->id();
            $t->string('event_id', 36)->unique();
            $t->string('type', 191);
            $t->integer('version');
            $t->string('aggregate_ref', 191);
            $t->text('payload');
            $t->string('occurred_at', 64);
            $t->string('available_at', 64)->nullable();
            $t->integer('attempts')->default(0);
            $t->string('delivered_at', 64)->nullable();
            $t->text('last_error')->nullable();
        });

        $schema->create('cmz_attributes', static function (Blueprint $t): void {
            $t->string('id', 36)->primary();
            $t->string('code', 64)->unique();
            $t->string('type', 64);
            $t->text('name');
            $t->text('config')->default('{}');
            $t->integer('config_version')->default(1);
            $t->boolean('filterable')->default(false);
            $t->string('status', 16)->default('active');
            $t->string('created_at', 64);
            $t->string('updated_at', 64);
        });

        $schema->create('cmz_attribute_choices', static function (Blueprint $t): void {
            $t->string('id', 36)->primary();
            $t->string('attribute_id', 36);
            $t->string('code', 64);
            $t->text('label');
            $t->integer('position')->default(0);
            $t->string('created_at', 64);
            $t->string('updated_at', 64);
            $t->unique(['attribute_id', 'code']);
        });

        $schema->create('cmz_options', static function (Blueprint $t): void {
            $t->string('id', 36)->primary();
            $t->string('code', 64)->unique();
            $t->text('name');
            $t->string('created_at', 64);
            $t->string('updated_at', 64);
        });

        $schema->create('cmz_option_values', static function (Blueprint $t): void {
            $t->string('id', 36)->primary();
            $t->string('option_id', 36);
            $t->string('code', 64);
            $t->text('label');
            $t->integer('position')->default(0);
            $t->string('created_at', 64);
            $t->string('updated_at', 64);
            $t->unique(['option_id', 'code']);
        });

        $schema->create('cmz_product_types', static function (Blueprint $t): void {
            $t->string('id', 36)->primary();
            $t->string('code', 64)->unique();
            $t->text('name');
            $t->string('kind', 64);
            $t->string('status', 16)->default('active');
            $t->string('created_at', 64);
            $t->string('updated_at', 64);
        });

        $schema->create('cmz_product_type_attributes', static function (Blueprint $t): void {
            $t->string('product_type_id', 36);
            $t->string('attribute_id', 36);
            $t->string('level', 32);
            $t->boolean('required')->default(false);
            $t->integer('position')->default(0);
            $t->primary(['product_type_id', 'attribute_id']);
        });

        $schema->create('cmz_product_type_options', static function (Blueprint $t): void {
            $t->string('product_type_id', 36);
            $t->string('option_id', 36);
            $t->integer('position')->default(0);
            $t->primary(['product_type_id', 'option_id']);
        });

        $schema->create('cmz_products', static function (Blueprint $t): void {
            $t->string('id', 36)->primary();
            $t->string('product_type_id', 36);
            $t->string('handle', 191)->unique();
            $t->text('name');
            $t->text('description')->nullable();
            $t->string('status', 16)->default('active');
            $t->text('attributes')->default('{}');
            $t->string('created_at', 64);
            $t->string('updated_at', 64);
        });

        $schema->create('cmz_product_options', static function (Blueprint $t): void {
            $t->string('product_id', 36);
            $t->string('option_id', 36);
            $t->integer('position')->default(0);
            $t->primary(['product_id', 'option_id']);
        });

        $schema->create('cmz_variants', static function (Blueprint $t): void {
            $t->string('id', 36)->primary();
            $t->string('product_id', 36);
            $t->string('sku', 191)->unique();
            $t->string('barcode', 64)->nullable()->unique();
            $t->string('option_signature', 512)->default('');
            $t->string('status', 16)->default('active');
            $t->bigInteger('position')->default(0);
            $t->text('attributes')->default('{}');
            $t->string('tax_class', 64)->nullable();
            $t->bigInteger('weight_grams')->nullable();
            $t->bigInteger('length_mm')->nullable();
            $t->bigInteger('width_mm')->nullable();
            $t->bigInteger('height_mm')->nullable();
            $t->string('created_at', 64);
            $t->string('updated_at', 64);
            $t->unique(['product_id', 'option_signature']);
        });

        $schema->create('cmz_variant_option_values', static function (Blueprint $t): void {
            $t->string('variant_id', 36);
            $t->string('product_id', 36);
            $t->string('option_id', 36);
            $t->string('option_value_id', 36);
            $t->primary(['variant_id', 'option_id']);
        });

        $schema->create('cmz_attribute_index', static function (Blueprint $t): void {
            $t->id();
            $t->string('product_id', 36);
            $t->string('variant_id', 36)->nullable();
            $t->string('attribute_id', 36);
            $t->string('choice_id', 36)->nullable();
            $t->string('num', 64)->nullable();
            $t->boolean('flag')->nullable();
            $t->string('day', 16)->nullable();
            $t->string('term', 255)->nullable();
        });

        $schema->create('cmz_categories', static function (Blueprint $t): void {
            $t->string('id', 36)->primary();
            $t->string('parent_id', 36)->nullable();
            $t->string('code', 64)->unique();
            $t->text('name');
            $t->bigInteger('position')->default(0);
            $t->string('status', 16)->default('active');
            $t->string('created_at', 64);
            $t->string('updated_at', 64);
        });

        $schema->create('cmz_category_products', static function (Blueprint $t): void {
            $t->string('category_id', 36);
            $t->string('product_id', 36);
            $t->integer('position')->default(0);
            $t->primary(['category_id', 'product_id']);
        });

        $schema->create('cmz_listings', static function (Blueprint $t): void {
            $t->string('product_id', 36);
            $t->string('channel_id', 36);
            $t->string('status', 16);
            $t->string('available_from', 64)->nullable();
            $t->string('available_until', 64)->nullable();
            $t->string('created_at', 64);
            $t->string('updated_at', 64);
            $t->primary(['product_id', 'channel_id']);
        });

        $schema->create('cmz_collections', static function (Blueprint $t): void {
            $t->string('id', 36)->primary();
            $t->string('code', 64)->unique();
            $t->text('name');
            $t->text('description')->nullable();
            $t->string('kind', 16);
            $t->text('rules')->nullable();
            $t->smallInteger('rules_version')->default(1);
            $t->string('status', 16)->default('active');
            $t->string('created_at', 64);
            $t->string('updated_at', 64);
        });

        $schema->create('cmz_collection_products', static function (Blueprint $t): void {
            $t->string('collection_id', 36);
            $t->string('product_id', 36);
            $t->bigInteger('position')->default(0);
            $t->primary(['collection_id', 'product_id']);
        });

        $schema->create('cmz_storefront_products', static function (Blueprint $t): void {
            $t->string('channel_id', 36);
            $t->string('locale', 32);
            $t->string('product_id', 36);
            $t->text('snapshot');
            $t->bigInteger('price_min_minor')->nullable();
            $t->bigInteger('price_max_minor')->nullable();
            $t->boolean('in_stock')->default(false);
            $t->string('updated_at', 64);
            $t->primary(['channel_id', 'locale', 'product_id']);
        });
    }
}
