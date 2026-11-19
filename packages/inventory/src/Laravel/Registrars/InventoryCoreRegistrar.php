<?php

declare(strict_types=1);

namespace Commerz\Inventory\Laravel\Registrars;

use Commerz\Inventory\Configuration\InventoryOptions;
use Commerz\Inventory\Diagnostics\InventoryDoctorCheck;
use Commerz\Inventory\Internal\Console\ExpireReservationsCommand;
use Commerz\Inventory\Internal\Console\ReconcileStockCommand;
use Commerz\Inventory\Plugins\InventoryPlugin;
use Commerz\Schema\CommerzSchemaSets;
use Illuminate\Console\Events\ArtisanStarting;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Override;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Foundation\Contracts\PackageRegistrar;
use Packman\Plugins\Contracts\Contributions\Contributions;

/**
 * Wires Commerz inventory plugin facets, options, schema ownership, diagnostics, panels, and console commands.
 */
#[Spi(kind: SpiKind::Call, since: '0.1')]
final class InventoryCoreRegistrar implements PackageRegistrar
{
    #[Override]
    public function register(Application $app): void
    {
        $app->singletonIf(InventoryPlugin::class);

        $app->singleton(InventoryDoctorCheck::class, static fn (Application $app): InventoryDoctorCheck => new InventoryDoctorCheck(
            config: $app->make(ConfigRepository::class),
            optionsResolver: static fn (): InventoryOptions => $app->make(InventoryOptions::class),
            contributionsResolver: static fn (): Contributions => $app->make(Contributions::class),
        ));

        CommerzSchemaSets::governMigrations($app);
    }

    #[Override]
    public function boot(Application $app): void
    {
        if ($app->runningInConsole()) {
            $app->make(Dispatcher::class)->listen(ArtisanStarting::class, static function (ArtisanStarting $event): void {
                $event->artisan->resolveCommands([
                    ExpireReservationsCommand::class,
                    ReconcileStockCommand::class,
                ]);
            });
        }
    }
}
