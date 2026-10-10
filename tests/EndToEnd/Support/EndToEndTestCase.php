<?php

declare(strict_types=1);

namespace Commerz\EndToEnd\Support;

use Commerz\Catalog\CatalogServiceProvider;
use Commerz\CommerzServiceProvider;
use Commerz\Inventory\InventoryServiceProvider;
use Commerz\Pricing\PricingServiceProvider;
use Commerz\Promotions\PromotionsServiceProvider;
use Commerz\Sales\SalesServiceProvider;
use Commerz\Shipping\ShippingServiceProvider;
use Commerz\Testkit\PackageTestCase;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The whole family in one application: the seven real providers, real stock (`commerz.stock = inventory`), no fakes.
 * A scenario that needs a subset overrides {@see self::commerzProviders()} and the stock mode.
 */
abstract class EndToEndTestCase extends PackageTestCase
{
    private static bool $reversed = false;

    /** @return list<class-string> every provider of the family, the core first */
    public static function allProviders(): array
    {
        return [
            CommerzServiceProvider::class,
            CatalogServiceProvider::class,
            InventoryServiceProvider::class,
            PricingServiceProvider::class,
            PromotionsServiceProvider::class,
            ShippingServiceProvider::class,
            SalesServiceProvider::class,
        ];
    }

    /** Boots a fresh application with the providers in the opposite order; the order is back to normal after the test. */
    protected function bootInReverseOrder(): void
    {
        self::$reversed = true;
        $this->refreshApplication();

        // The new application has new connections: the test transaction of `RefreshDatabase` must wrap them too.
        if (in_array(RefreshDatabase::class, class_uses_recursive($this), true)) {
            $this->beginDatabaseTransaction();
        }
    }

    protected function commerzProviders(): array
    {
        $providers = self::allProviders();

        return self::$reversed ? array_reverse($providers) : $providers;
    }

    /** @param  Application  $app */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('commerz.stock', 'inventory');
    }

    protected function tearDown(): void
    {
        self::$reversed = false;

        parent::tearDown();
    }
}
