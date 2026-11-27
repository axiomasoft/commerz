<?php

declare(strict_types=1);

use Commerz\Catalog\Schema\CatalogSchemaSets;
use Commerz\Inventory\Schema\InventorySchemaSets;
use Commerz\Pricing\Schema\PricingSchemaSets;
use Commerz\Promotions\Schema\PromotionsSchemaSets;
use Commerz\Sales\Schema\SalesSchemaSets;
use Commerz\Schema\CommerzSchemaSets;
use Commerz\Shipping\Schema\ShippingSchemaSets;
use Illuminate\Config\Repository;
use Packman\Schema\Enums\SchemaScope;

it('defaults all Commerz schema sets to Central with null connection', function (): void {
    $providers = [
        new CommerzSchemaSets,
        new CatalogSchemaSets,
        new InventorySchemaSets,
        new PricingSchemaSets,
        new PromotionsSchemaSets,
        new SalesSchemaSets,
        new ShippingSchemaSets,
    ];

    foreach ($providers as $provider) {
        $sets = $provider->sets();
        expect($sets)->toHaveCount(1)
            ->and($sets[0]->scope)->toBe(SchemaScope::Central)
            ->and($sets[0]->connection)->toBeNull();
    }
});

it('propagates Tenant and Dedicated schema_scope to all Commerz schema sets without tenant_id coupling', function (): void {
    $tenantConfig = new Repository(['commerz' => ['connection' => 'tenant_db', 'schema_scope' => 'tenant']]);
    $dedicatedConfig = new Repository(['commerz' => ['connection' => 'commerce_db', 'schema_scope' => 'dedicated']]);

    $classes = [
        CommerzSchemaSets::class,
        CatalogSchemaSets::class,
        InventorySchemaSets::class,
        PricingSchemaSets::class,
        PromotionsSchemaSets::class,
        SalesSchemaSets::class,
        ShippingSchemaSets::class,
    ];

    foreach ($classes as $class) {
        $tenantSet = new $class($tenantConfig)->sets()[0];
        expect($tenantSet->scope)->toBe(SchemaScope::Tenant)
            ->and($tenantSet->connection)->toBeNull();

        $dedicatedSet = new $class($dedicatedConfig)->sets()[0];
        expect($dedicatedSet->scope)->toBe(SchemaScope::Dedicated)
            ->and($dedicatedSet->connection)->toBe('commerce_db');
    }
});
