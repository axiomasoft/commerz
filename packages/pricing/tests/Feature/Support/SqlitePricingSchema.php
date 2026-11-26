<?php

declare(strict_types=1);

use Commerz\Configuration\CommerzOptions;
use Commerz\Operations\Contracts\Transactions;
use Commerz\Ports\Prices\Contracts\PriceResolver;
use Commerz\Ports\Taxes\Contracts\TaxEngine;
use Commerz\Pricing\Markets\Contracts\Markets;
use Commerz\Pricing\PriceLists\Contracts\PriceLists;
use Commerz\Pricing\Prices\Contracts\Prices;
use Commerz\Pricing\Prices\Contracts\StagedPrices;
use Commerz\Pricing\Prices\Internal\DatabasePriceResolver;
use Commerz\Pricing\Prices\Internal\PriceCandidates;
use Commerz\Pricing\Prices\Internal\PriceHistoryJournal;
use Commerz\Pricing\Prices\Internal\PriceWrites;
use Commerz\Pricing\Prices\Internal\StagedPriceOverlay;
use Commerz\Pricing\Pricing as PricingRoot;
use Commerz\Pricing\Taxes\Contracts\Taxes;
use Commerz\Pricing\Taxes\Internal\PricingTaxEngine;
use Commerz\Pricing\Taxes\Internal\TaxRateLookup;
use Commerz\Pricing\Taxes\Internal\TaxZoneLookup;
use Commerz\Schema\TableNames;
use Commerz\Stores\Contracts\Channels;
use Commerz\Stores\Contracts\Stores;
use Commerz\Testkit\Fakes\FrozenClock;
use Commerz\Time\Contracts\Clock;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;

if (! function_exists('bootSqlitePricingSchema')) {
    function bootSqlitePricingSchema(string $connection = 'pricing_sqlite', string $initialTime = '2026-11-26T12:00:00Z'): FrozenClock
    {
        config()->set("database.connections.{$connection}", [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        config()->set('commerz.connection', $connection);
        DB::purge($connection);

        $clock = new FrozenClock($initialTime);
        app()->instance(Clock::class, $clock);

        app()->instance(Transactions::class, new readonly class($connection) implements Transactions
        {
            public function __construct(private string $connection) {}

            public function run(callable $work, int $lockTimeoutMs = 3000): mixed
            {
                return DB::connection($this->connection)->transaction(static fn () => $work());
            }

            public function inTransaction(): bool
            {
                return DB::connection($this->connection)->transactionLevel() > 0;
            }
        });

        foreach ([
            CommerzOptions::class,
            TableNames::class,
            Stores::class,
            Channels::class,
            StagedPriceOverlay::class,
            PriceHistoryJournal::class,
            PriceCandidates::class,
            PriceWrites::class,
            PriceLists::class,
            Markets::class,
            DatabasePriceResolver::class,
            PriceResolver::class,
            Prices::class,
            StagedPrices::class,
            TaxZoneLookup::class,
            TaxRateLookup::class,
            Taxes::class,
            PricingTaxEngine::class,
            TaxEngine::class,
            PricingRoot::class,
        ] as $service) {
            app()->forgetInstance($service);
        }

        Facade::clearResolvedInstances();

        $schema = Schema::connection($connection);

        $schema->create('cmz_stores', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 64)->unique();
            $table->string('name', 255);
            $table->char('default_currency', 3);
            $table->string('default_locale', 16)->default('en');
            $table->string('order_number_prefix', 16)->default('');
            $table->string('status', 16)->default('active');
            $table->string('created_at', 64);
            $table->string('updated_at', 64);
        });

        $schema->create('cmz_channels', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('store_id');
            $table->string('code', 64)->unique();
            $table->string('name', 255);
            $table->text('currencies');
            $table->char('default_currency', 3);
            $table->text('locales');
            $table->string('default_locale', 16)->default('en');
            $table->boolean('prices_include_tax')->default(false);
            $table->string('status', 16)->default('active');
            $table->string('created_at', 64);
            $table->string('updated_at', 64);
        });

        $schema->create('cmz_price_lists', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 64)->unique();
            $table->string('name', 255);
            $table->char('currency', 3);
            $table->integer('priority')->default(0);
            $table->boolean('tax_included')->nullable();
            $table->uuid('parent_price_list_id')->nullable();
            $table->text('formula')->nullable();
            $table->text('rounding_rule')->nullable();
            $table->string('status', 16)->default('active');
            $table->string('created_at', 64);
            $table->string('updated_at', 64);
        });

        $schema->create('cmz_price_list_channels', static function (Blueprint $table): void {
            $table->uuid('price_list_id');
            $table->uuid('channel_id');
            $table->string('created_at', 64);
            $table->primary(['price_list_id', 'channel_id']);
        });

        $schema->create('cmz_prices', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('price_list_id');
            $table->string('sellable_ref', 191);
            $table->string('customer_group', 64)->nullable();
            $table->bigInteger('min_quantity')->default(1);
            $table->bigInteger('amount_minor');
            $table->bigInteger('compare_at_minor')->nullable();
            $table->char('currency', 3);
            $table->string('valid_from', 64)->nullable();
            $table->string('valid_until', 64)->nullable();
            $table->string('created_at', 64);
            $table->string('updated_at', 64);
        });

        $schema->create('cmz_staged_prices', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('batch_code', 64)->nullable();
            $table->uuid('price_list_id');
            $table->string('sellable_ref', 191);
            $table->string('customer_group', 64)->nullable();
            $table->bigInteger('min_quantity')->default(1);
            $table->bigInteger('amount_minor');
            $table->bigInteger('compare_at_minor')->nullable();
            $table->char('currency', 3);
            $table->string('valid_from', 64)->nullable();
            $table->string('valid_until', 64)->nullable();
            $table->string('status', 16)->default('staged');
            $table->uuid('published_price_id')->nullable();
            $table->string('published_at', 64)->nullable();
            $table->string('created_at', 64);
            $table->string('updated_at', 64);
        });

        $schema->create('cmz_price_history', static function (Blueprint $table): void {
            $table->id();
            $table->uuid('price_id');
            $table->uuid('price_list_id');
            $table->string('sellable_ref', 191);
            $table->string('customer_group', 64)->nullable();
            $table->bigInteger('min_quantity')->default(1);
            $table->bigInteger('amount_minor');
            $table->bigInteger('compare_at_minor')->nullable();
            $table->char('currency', 3);
            $table->string('event_type', 16);
            $table->string('valid_from', 64)->nullable();
            $table->string('valid_until', 64)->nullable();
            $table->string('recorded_at', 64);
        });

        $schema->create('cmz_tax_classes', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 64)->unique();
            $table->string('name', 255);
            $table->string('created_at', 64);
            $table->string('updated_at', 64);
        });

        $schema->create('cmz_tax_zones', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 64)->unique();
            $table->string('name', 255);
            $table->string('created_at', 64);
            $table->string('updated_at', 64);
        });

        $schema->create('cmz_tax_zone_members', static function (Blueprint $table): void {
            $table->id();
            $table->uuid('tax_zone_id');
            $table->char('country', 2);
            $table->string('region', 6)->nullable();
            $table->unique(['country', 'region']);
        });

        $schema->create('cmz_tax_rates', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tax_class_id');
            $table->uuid('tax_zone_id');
            $table->string('code', 32)->default('vat');
            $table->integer('rate_bp');
            $table->string('valid_from', 64)->nullable();
            $table->string('valid_until', 64)->nullable();
            $table->string('created_at', 64);
            $table->string('updated_at', 64);
        });

        $schema->create('cmz_markets', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 64)->unique();
            $table->string('name', 255);
            $table->uuid('channel_id');
            $table->char('default_currency', 3);
            $table->text('allowed_currencies');
            $table->boolean('tax_included')->default(false);
            $table->uuid('default_price_list_id')->nullable();
            $table->string('status', 16)->default('active');
            $table->string('created_at', 64);
            $table->string('updated_at', 64);
        });

        $schema->create('cmz_market_countries', static function (Blueprint $table): void {
            $table->uuid('market_id');
            $table->uuid('channel_id');
            $table->char('country', 2);
            $table->primary(['market_id', 'country']);
            $table->unique(['channel_id', 'country']);
        });

        return $clock;
    }
}
