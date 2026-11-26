<?php

declare(strict_types=1);

namespace Commerz\Inventory\Laravel\Registrars;

use Commerz\Inventory\Allocation\Contracts\OrderRouter;
use Commerz\Inventory\Allocation\OrderRouterPipeline;
use Commerz\Inventory\Availability\Internal\DatabaseAvailability;
use Commerz\Inventory\Common\Internal\LevelQueries;
use Commerz\Inventory\Common\Internal\StockLedger;
use Commerz\Inventory\Internal\Reconciler;
use Commerz\Inventory\Inventory;
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
use Commerz\Inventory\Transfers\Contracts\Transfers;
use Commerz\Inventory\Transfers\Internal\DatabaseTransfers;
use Commerz\Ports\Stock\Contracts\StockAvailability;
use Commerz\Ports\Stock\Contracts\StockReservations;
use Commerz\Ports\Stock\Contracts\StockReturns;
use Illuminate\Contracts\Foundation\Application;
use Override;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Foundation\Concerns\RegistersNothingOnBoot;
use Packman\Foundation\Contracts\PackageRegistrar;

/**
 * Registers inventory repositories, stock port implementations, reconciler, and root Inventory facade target.
 */
#[Spi(kind: SpiKind::Call, since: '0.1')]
final class InventoryPortsRegistrar implements PackageRegistrar
{
    use RegistersNothingOnBoot;

    #[Override]
    public function register(Application $app): void
    {
        $app->singleton(LevelQueries::class);
        $app->singleton(StockLedger::class);
        $app->singleton(StockEntities::class);
        $app->singleton(Locations::class, DatabaseLocations::class);
        $app->singleton(StockItems::class, DatabaseStockItems::class);
        $app->singleton(StockSources::class, DatabaseStockSources::class);
        $app->singleton(StockLevels::class, DatabaseStockLevels::class);
        $app->singleton(Movements::class, DatabaseMovements::class);
        $app->singleton(DatabaseReservations::class);
        $app->singleton(Reservations::class, static fn (Application $app): DatabaseReservations => $app->make(DatabaseReservations::class));
        // Plain singletons replace the kernel's `singletonIf` defaults whichever provider registers first; the
        // reservation port and the package contract are one instance.
        $app->singleton(StockReservations::class, static fn (Application $app): Reservations => $app->make(Reservations::class));
        $app->singleton(StockAvailability::class, DatabaseAvailability::class);
        $app->singleton(StockReturns::class, DatabaseStockReturns::class);
        $app->singleton(Transfers::class, DatabaseTransfers::class);
        $app->singletonIf(OrderRouter::class, static fn (): OrderRouter => OrderRouterPipeline::default());
        $app->singleton(Reconciler::class);
        $app->singleton(Inventory::class);
    }
}
