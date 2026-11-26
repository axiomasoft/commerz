<?php

declare(strict_types=1);

use Commerz\Links\Contracts\CrossModuleQuery;
use Commerz\Links\Contracts\LinkRegistry;
use Commerz\Links\Data\LinkDefinition;
use Commerz\Links\Enums\LinkCardinality;
use Commerz\Money\Currency;
use Commerz\Stores\Contracts\Stores;
use Commerz\Stores\Data\CreateStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('creates cmz_entity_links with UUIDv7 PK, jsonb extra, forward/reverse indexes, and zero FKs', function (): void {
    $columns = collect(DB::select("select column_name, data_type from information_schema.columns where table_name = 'cmz_entity_links'"))
        ->keyBy('column_name');

    expect($columns->keys()->sort()->values()->all())->toBe([
        'created_at',
        'extra',
        'id',
        'left_id',
        'left_type',
        'link_code',
        'position',
        'right_id',
        'right_type',
        'updated_at',
    ])
        ->and($columns['id']->data_type)->toBe('uuid')
        ->and($columns['extra']->data_type)->toBe('jsonb');

    $foreignKeys = DB::select("select conname from pg_constraint where conrelid = 'cmz_entity_links'::regclass and contype = 'f'");
    $indexes = array_map(
        static fn (object $row): string => $row->indexname,
        DB::select("select indexname from pg_indexes where tablename = 'cmz_entity_links' order by indexname"),
    );

    expect($foreignKeys)->toBe([])
        ->and($indexes)->toHaveCount(4); // pk + unique + forward composite + reverse composite
});

it('executes N+1-free batch queries (2 queries for 100 entities) and whereLinked against uuid tables on PostgreSQL', function (): void {
    $registry = app(LinkRegistry::class);
    $query = app(CrossModuleQuery::class);
    $stores = app(Stores::class);

    $storeA = $stores->create(new CreateStore('store-a', 'Store A', Currency::of('RUB')));
    $storeB = $stores->create(new CreateStore('store-b', 'Store B', Currency::of('RUB')));

    $registry->define(new LinkDefinition('core.store_collections', 'store', 'collection', LinkCardinality::ManyToMany));
    $registry->link('core.store_collections', $storeA->id->value, 'col-1', 0, ['featured' => true]);

    $matchedStores = $query->whereLinked(DB::table('cmz_stores'), 'core.store_collections', 'cmz_stores.id', 'col-1')
        ->pluck('code')
        ->all();

    expect($matchedStores)->toBe(['store-a']);

    $registry->define(new LinkDefinition('core.batch_items', 'item', 'store', LinkCardinality::ManyToMany));
    $itemIds = [];
    for ($i = 1; $i <= 100; $i++) {
        $id = sprintf('item-%03d', $i);
        $itemIds[] = $id;
        $registry->link('core.batch_items', $id, $i % 2 === 0 ? $storeA->id->value : $storeB->id->value);
    }

    DB::flushQueryLog();
    DB::enableQueryLog();

    $loaded = $query->getLinkedForMany(
        'core.batch_items',
        $itemIds,
        static function (array $storeIds): array {
            $rows = DB::table('cmz_stores')->whereIn('id', $storeIds)->get();
            $map = [];
            foreach ($rows as $row) {
                $map[$row->id] = $row->code;
            }

            return $map;
        },
    );

    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($queries)->toHaveCount(2)
        ->and($loaded)->toHaveCount(100)
        ->and($loaded['item-001'])->toBe(['store-b'])
        ->and($loaded['item-002'])->toBe(['store-a']);
});
