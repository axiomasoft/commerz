<?php

declare(strict_types=1);

use Commerz\Identity\Uuid7;
use Commerz\Schema\TableNames;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('enforces PostgreSQL CHECK constraints for overridable_variant, collections, and storefront_products', function (): void {
    $tables = app(TableNames::class);
    $typeId = Uuid7::generate();
    $attrId = Uuid7::generate();
    $productId = Uuid7::generate();
    $name = json_encode(['en' => 'Test'], JSON_THROW_ON_ERROR);

    DB::table($tables->table('attributes'))->insert(['id' => $attrId, 'code' => 'mat', 'type' => 'text', 'name' => $name]);
    DB::table($tables->table('product_types'))->insert(['id' => $typeId, 'code' => 'coat', 'name' => $name, 'kind' => 'physical']);
    DB::table($tables->table('product_type_attributes'))->insert([
        'product_type_id' => $typeId,
        'attribute_id' => $attrId,
        'level' => 'overridable_variant',
    ]);
    DB::table($tables->table('products'))->insert([
        'id' => $productId,
        'product_type_id' => $typeId,
        'handle' => 'coat-1',
        'name' => $name,
    ]);

    expect(fn () => DB::transaction(fn () => DB::table($tables->table('collections'))->insert([
        'id' => Uuid7::generate(),
        'code' => 'bad-smart',
        'name' => $name,
        'kind' => 'smart',
        'rules' => null,
    ])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table($tables->table('storefront_products'))->insert([
            'channel_id' => Uuid7::generate(),
            'locale' => 'en',
            'product_id' => $productId,
            'snapshot' => '{}',
            'price_min_minor' => 500,
            'price_max_minor' => 100,
            'in_stock' => true,
        ])))->toThrow(QueryException::class);
});
