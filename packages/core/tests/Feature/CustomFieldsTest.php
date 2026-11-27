<?php

declare(strict_types=1);

use Commerz\Configuration\CommerzOptions;
use Commerz\CustomFields\Contracts\CustomFieldIndexer;
use Commerz\CustomFields\Contracts\CustomFieldQuery;
use Commerz\CustomFields\Contracts\CustomFieldRegistry;
use Commerz\CustomFields\Data\CustomFieldDefinition;
use Commerz\CustomFields\Data\CustomFieldFilter;
use Commerz\CustomFields\Enums\CustomFieldType;
use Commerz\CustomFields\Enums\FieldVisibility;
use Commerz\CustomFields\Exceptions\CustomFieldNotFilterable;
use Commerz\CustomFields\Exceptions\CustomFieldVisibilityViolation;
use Commerz\Metadata\MetadataBag;
use Commerz\Plugins\CorePlugin;
use Commerz\Schema\TableNames;
use Commerz\Testkit\Fakes\FrozenClock;
use Commerz\Time\Contracts\Clock;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Packman\Schema\Contracts\Compiler\SchemaOwnership;
use Packman\Schema\Laravel\SchemaExtensions;

final class IsolatedIndexMigration
{
    public function __construct(private object $migration) {}

    public function up(): void
    {
        $migration = $this->migration;

        if (! method_exists($migration, 'up')) {
            throw new RuntimeException('Migration is missing up().');
        }

        $migration->up();
    }

    public function down(): void
    {
        $migration = $this->migration;

        if (! method_exists($migration, 'down')) {
            throw new RuntimeException('Migration is missing down().');
        }

        $migration->down();
    }
}

function bootIsolatedIndexDatabase(): IsolatedIndexMigration
{
    config()->set('database.connections.cf_sqlite_test', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
    ]);
    config()->set('commerz.connection', 'cf_sqlite_test');
    config()->set('commerz.table_prefix', 'cmz_');

    app()->forgetInstance(CommerzOptions::class);
    app()->forgetInstance(TableNames::class);
    app()->forgetInstance(CustomFieldIndexer::class);
    app()->forgetInstance(CustomFieldQuery::class);

    $loaded = require dirname(__DIR__, 2).'/database/migrations/2026_11_26_100001_create_commerz_custom_field_index_table.php';

    if (! is_object($loaded)) {
        throw new RuntimeException('Expected the custom field index migration.');
    }

    $migration = new IsolatedIndexMigration($loaded);
    $migration->up();

    DB::connection('cf_sqlite_test')->getSchemaBuilder()->create('cmz_sample_orders', static function (Blueprint $table): void {
        $table->string('id', 64)->primary();
        $table->string('status', 32);
    });

    return $migration;
}

it('registers CustomFields contracts, SchemaOwnership noun, and keeps CommerzServiceProvider <= 80 lines', function (): void {
    $migration = bootIsolatedIndexDatabase();

    $providerFile = file(dirname(__DIR__, 2).'/src/CommerzServiceProvider.php');
    $providerLines = count($providerFile === false ? [] : $providerFile);
    $ownership = SchemaExtensions::ownershipFor(app(SchemaOwnership::class), (new CorePlugin)->tablePrefix());

    app(CustomFieldRegistry::class);
    app(CustomFieldIndexer::class);
    app(CustomFieldQuery::class);

    expect($providerLines)->toBeLessThanOrEqual(80)
        ->and($ownership->ownerOf('custom_field_index'))->toBe('commerz.core')
        ->and(app(TableNames::class)->table('custom_field_index'))->toBe('cmz_custom_field_index');

    $migration->down();
});

it('indexes filterable custom fields into cmz_custom_field_index and enforces visibility separation in queries', function (): void {
    $clock = new FrozenClock('2026-11-26T12:00:00+00:00');
    app()->instance(Clock::class, $clock);

    $migration = bootIsolatedIndexDatabase();

    /** @var CustomFieldRegistry $registry */
    $registry = app(CustomFieldRegistry::class);
    $registry->register(
        new CustomFieldDefinition('order', 'storefront', 'delivery_window', CustomFieldType::Enum, FieldVisibility::PublicStorefront, isFilterable: true, validationRules: ['choices' => ['morning', 'evening']]),
        new CustomFieldDefinition('order', 'storefront', 'gift_wrap', CustomFieldType::Boolean, FieldVisibility::PublicStorefront, isFilterable: true),
        new CustomFieldDefinition('order', 'storefront', 'declared_weight', CustomFieldType::Decimal, FieldVisibility::PublicStorefront, isFilterable: true, validationRules: ['scale' => 2]),
        new CustomFieldDefinition('order', 'storefront', 'requested_date', CustomFieldType::Date, FieldVisibility::PublicStorefront, isFilterable: true),
        new CustomFieldDefinition('order', 'storefront', 'gift_message', CustomFieldType::String, FieldVisibility::PublicStorefront, isFilterable: false),
        new CustomFieldDefinition('order', 'crm', 'risk_score', CustomFieldType::Integer, FieldVisibility::PrivateAdmin, isFilterable: true, validationRules: ['min' => 0, 'max' => 100]),
        new CustomFieldDefinition('order', 'crm', 'tags', CustomFieldType::MultiEnum, FieldVisibility::PrivateAdmin, isFilterable: true, validationRules: ['choices' => ['fraud_review', 'high_value', 'b2b']]),
    );

    $db = DB::connection('cf_sqlite_test');
    $db->table('cmz_sample_orders')->insert([
        ['id' => 'ord-1', 'status' => 'placed'],
        ['id' => 'ord-2', 'status' => 'placed'],
        ['id' => 'ord-3', 'status' => 'cancelled'],
    ]);

    /** @var CustomFieldIndexer $indexer */
    $indexer = app(CustomFieldIndexer::class);

    $indexer->sync('order', 'ord-1', new MetadataBag(
        public: [
            'storefront.delivery_window' => 'morning',
            'storefront.gift_wrap' => true,
            'storefront.declared_weight' => '2.5',
            'storefront.requested_date' => '2026-12-01',
            'storefront.gift_message' => 'Enjoy!',
        ],
        private: [
            'crm.risk_score' => 15,
            'crm.tags' => ['high_value', 'b2b'],
        ],
    ));

    $indexer->sync('order', 'ord-2', new MetadataBag(
        public: [
            'storefront.delivery_window' => 'evening',
            'storefront.gift_wrap' => false,
            'storefront.declared_weight' => '12.00',
            'storefront.requested_date' => '2026-12-10',
        ],
        private: [
            'crm.risk_score' => 85,
            'crm.tags' => ['fraud_review'],
        ],
    ));

    $indexer->sync('order', 'ord-3', new MetadataBag(
        public: [
            'storefront.delivery_window' => 'morning',
            'storefront.gift_wrap' => true,
            'storefront.declared_weight' => '5.00',
            'storefront.requested_date' => '2026-12-05',
        ],
        private: [
            'crm.risk_score' => 40,
            'crm.tags' => ['b2b'],
        ],
    ));

    // Gift message is non-filterable, so ord-1 produces 4 public + 3 private (risk_score + 2 tags) = 7 rows.
    expect($db->table('cmz_custom_field_index')->where('aggregate_id', 'ord-1')->count())->toBe(7)
        ->and($db->table('cmz_custom_field_index')->where('aggregate_id', 'ord-1')->value('indexed_at'))
        ->toBe('2026-11-26 12:00:00.000000+00:00');

    /** @var CustomFieldQuery $query */
    $query = app(CustomFieldQuery::class);

    // PublicStorefront queries match public fields (with decimal scale normalization '2.50' == '2.5' and ranges).
    expect($query->matchingIds('order', [
        CustomFieldFilter::equals('storefront', 'delivery_window', 'morning'),
        CustomFieldFilter::equals('storefront', 'gift_wrap', true),
        CustomFieldFilter::between('storefront', 'declared_weight', '2.0', '6.0'),
    ], FieldVisibility::PublicStorefront))->toBe(['ord-1', 'ord-3']);

    expect($query->matchingIds('order', [
        CustomFieldFilter::between('storefront', 'requested_date', '2026-12-04', '2026-12-12'),
    ], FieldVisibility::PublicStorefront))->toBe(['ord-2', 'ord-3']);

    // Applying filters onto an outer aggregate query builder with alias & status condition:
    $placedMorning = $query->applyFilters(
        $db->table('cmz_sample_orders as o')->where('o.status', 'placed')->select('o.id'),
        'order',
        'o.id',
        [CustomFieldFilter::equals('storefront', 'delivery_window', 'morning')],
        FieldVisibility::PublicStorefront,
    )->pluck('id')->all();

    expect($placedMorning)->toBe(['ord-1']);

    // Non-filterable public field is rejected even on storefront:
    expect(fn () => $query->matchingIds('order', [
        CustomFieldFilter::equals('storefront', 'gift_message', 'Enjoy!'),
    ], FieldVisibility::PublicStorefront))->toThrow(CustomFieldNotFilterable::class);

    // Visibility separation: PublicStorefront is forbidden from querying PrivateAdmin fields!
    expect(fn () => $query->matchingIds('order', [
        CustomFieldFilter::between('crm', 'risk_score', 50, 100),
    ], FieldVisibility::PublicStorefront))->toThrow(CustomFieldVisibilityViolation::class);

    expect(fn () => $query->applyFilters(
        $db->table('cmz_sample_orders'),
        'order',
        'id',
        [CustomFieldFilter::equals('crm', 'tags', 'fraud_review')],
        FieldVisibility::PublicStorefront,
    ))->toThrow(CustomFieldVisibilityViolation::class);

    // PrivateAdmin audience can query across both public and private fields simultaneously:
    expect($query->matchingIds('order', [
        CustomFieldFilter::equals('storefront', 'delivery_window', 'morning'),
        CustomFieldFilter::in('crm', 'tags', ['b2b']),
        CustomFieldFilter::between('crm', 'risk_score', 10, 30),
    ], FieldVisibility::PrivateAdmin))->toBe(['ord-1']);

    // Cleanup operations (`deleteAggregate` & `deleteField` & `down()`):
    $indexer->deleteAggregate('order', 'ord-3');
    expect($query->matchingIds('order', [
        CustomFieldFilter::equals('storefront', 'delivery_window', 'morning'),
    ], FieldVisibility::PublicStorefront))->toBe(['ord-1']);

    $indexer->deleteField('order', 'crm', 'risk_score');
    expect($query->matchingIds('order', [
        CustomFieldFilter::between('crm', 'risk_score', 0, 100),
    ], FieldVisibility::PrivateAdmin))->toBe([]);

    $migration->down();
    expect($db->getSchemaBuilder()->hasTable('cmz_custom_field_index'))->toBeFalse();
});
