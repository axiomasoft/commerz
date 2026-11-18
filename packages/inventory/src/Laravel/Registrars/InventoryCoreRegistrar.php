<?php

declare(strict_types=1);

namespace Commerz\Inventory\Laravel\Registrars;

use Commerz\Inventory\Configuration\InventoryOptions;
use Commerz\Inventory\Diagnostics\InventoryDoctorCheck;
use Commerz\Inventory\Internal\Console\ExpireReservationsCommand;
use Commerz\Inventory\Internal\Console\ReconcileStockCommand;
use Commerz\Inventory\Plugins\InventoryPlugin;
use Commerz\Plugins\CommerzPlugins;
use Illuminate\Console\Application as Artisan;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Application as FoundationApplication;
use Override;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Extensions\Laravel\ExtensionFacetRegistrar;
use Packman\Foundation\Contracts\PackageRegistrar;
use Packman\PackmanServiceProvider;
use Packman\Plugins\Contracts\Contributions\Contributions;
use Packman\Plugins\Laravel\PluginsServiceProvider;
use Packman\Schema\Laravel\SchemaExtensions;
use Packman\Schema\Laravel\SchemaServiceProvider;

/**
 * Wires Commerz inventory plugin facets, options, schema ownership, diagnostics, panels, and console commands.
 */
#[Spi(kind: SpiKind::Call, since: '0.1')]
final class InventoryCoreRegistrar implements PackageRegistrar
{
    #[Override]
    public function register(Application $app): void
    {
        foreach ([
            PackmanServiceProvider::class,
            PluginsServiceProvider::class,
            SchemaServiceProvider::class,
        ] as $provider) {
            if ($app instanceof FoundationApplication && $app->getProvider($provider) === null) {
                $app->register($provider);
            }
        }

        $app->singletonIf(InventoryPlugin::class);

        $app->singleton(InventoryDoctorCheck::class, static fn (Application $app): InventoryDoctorCheck => new InventoryDoctorCheck(
            config: $app->make(ConfigRepository::class),
            optionsResolver: static fn (): InventoryOptions => $app->make(InventoryOptions::class),
            contributionsResolver: static fn (): Contributions => $app->make(Contributions::class),
        ));

        if (! $app->bound('commerz.inventory_facets_registered')) {
            $app->instance('commerz.inventory_facets_registered', true);

            ExtensionFacetRegistrar::register(
                $app,
                InventoryPlugin::class,
                dirname(__DIR__, 3).'/composer.json',
            );

            SchemaExtensions::register($app, InventoryPlugin::class, InventoryPlugin::ID);
        }

        CommerzPlugins::builtIn(InventoryPlugin::class);
    }

    #[Override]
    public function boot(Application $app): void
    {
        if ($app->runningInConsole()) {
            Artisan::starting(static function (Artisan $artisan): void {
                $artisan->resolveCommands([
                    ExpireReservationsCommand::class,
                    ReconcileStockCommand::class,
                ]);
            });
        }
    }
}
