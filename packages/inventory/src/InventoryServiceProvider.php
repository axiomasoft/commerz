<?php

declare(strict_types=1);

namespace Commerz\Inventory;

use Commerz\Attributes\Api;
use Commerz\Inventory\Availability\Internal\DatabaseAvailability;
use Commerz\Inventory\Common\Internal\InventoryConfig;
use Commerz\Inventory\Common\Internal\LevelQueries;
use Commerz\Inventory\Common\Internal\StockLedger;
use Commerz\Inventory\Internal\Console\ExpireReservationsCommand;
use Commerz\Inventory\Internal\InventoryPlugin;
use Commerz\Inventory\Items\Contracts\StockItems;
use Commerz\Inventory\Items\Internal\DatabaseStockItems;
use Commerz\Inventory\Locations\Contracts\Locations;
use Commerz\Inventory\Locations\Internal\DatabaseLocations;
use Commerz\Inventory\Movements\Contracts\Movements;
use Commerz\Inventory\Movements\Internal\DatabaseMovements;
use Commerz\Inventory\Reservations\Contracts\Reservations;
use Commerz\Inventory\Reservations\Internal\DatabaseReservations;
use Commerz\Inventory\Sources\Contracts\StockSources;
use Commerz\Inventory\Sources\Internal\DatabaseStockSources;
use Commerz\Inventory\Stock\Contracts\StockLevels;
use Commerz\Inventory\Stock\Internal\DatabaseStockLevels;
use Commerz\Inventory\Stock\Internal\DatabaseStockReturns;
use Commerz\Inventory\Stock\Internal\StockEntities;
use Commerz\Plugins\CommerzPlugins;
use Commerz\Ports\Stock\Contracts\StockAvailability;
use Commerz\Ports\Stock\Contracts\StockReservations;
use Commerz\Ports\Stock\Contracts\StockReturns;
use Commerz\Schema\Contracts\SchemaOwnership;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

#[Api(since: '0.1')]
final class InventoryServiceProvider extends ServiceProvider
{
    public const array NOUNS = ['stock'];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/commerz-inventory.php', 'commerz-inventory');

        $this->app->singleton(InventoryConfig::class, static function (Application $app): InventoryConfig {
            $config = $app->make(Repository::class)->get('commerz-inventory', []);

            return InventoryConfig::fromArray(is_array($config) ? $config : []);
        });

        $this->app->singleton(LevelQueries::class);
        $this->app->singleton(StockLedger::class);
        $this->app->singleton(StockEntities::class);
        $this->app->singleton(Locations::class, DatabaseLocations::class);
        $this->app->singleton(StockItems::class, DatabaseStockItems::class);
        $this->app->singleton(StockSources::class, DatabaseStockSources::class);
        $this->app->singleton(StockLevels::class, DatabaseStockLevels::class);
        $this->app->singleton(Movements::class, DatabaseMovements::class);
        $this->app->singleton(Reservations::class, DatabaseReservations::class);
        // Plain singletons replace the kernel's `singletonIf` defaults whichever provider registers first; the
        // reservation port and the package contract are one instance.
        $this->app->singleton(StockReservations::class, static fn (Application $app): Reservations => $app->make(Reservations::class));
        $this->app->singleton(StockAvailability::class, DatabaseAvailability::class);
        $this->app->singleton(StockReturns::class, DatabaseStockReturns::class);
        $this->app->singleton(Inventory::class);

        CommerzPlugins::builtIn(InventoryPlugin::class);

        $this->callAfterResolving(SchemaOwnership::class, static function (SchemaOwnership $ownership): void {
            $ownership->declare('commerz.inventory', self::NOUNS);
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/commerz-inventory.php' => config_path('commerz-inventory.php'),
            ], 'commerz-inventory-config');

            $this->commands([ExpireReservationsCommand::class]);
        }
    }
}
