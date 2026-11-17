<?php

declare(strict_types=1);

namespace Commerz\Inventory;

use Commerz\Inventory\Laravel\Registrars\InventoryCoreRegistrar;
use Commerz\Inventory\Laravel\Registrars\InventoryPortsRegistrar;
use Illuminate\Support\ServiceProvider;
use Override;
use Packman\Attributes\Api;
use Packman\Foundation\Concerns\RegistersPackageRegistrars;
use Packman\Foundation\Contracts\PackageRegistrar;

#[Api(since: '0.1')]
final class InventoryServiceProvider extends ServiceProvider
{
    use RegistersPackageRegistrars {
        register as protected registerRegistrars;
        boot as protected bootRegistrars;
    }

    public const array NOUNS = ['stock'];

    /** @var list<class-string<PackageRegistrar>> */
    protected const array REGISTRARS = [
        InventoryCoreRegistrar::class,
        InventoryPortsRegistrar::class,
    ];

    #[Override]
    public function register(): void
    {
        $this->registerRegistrars();
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/commerz-inventory.php' => $this->app->configPath('commerz-inventory.php'),
            ], 'commerz-inventory-config');
        }

        $this->bootRegistrars();
    }
}
