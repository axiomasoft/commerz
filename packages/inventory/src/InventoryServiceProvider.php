<?php

declare(strict_types=1);

namespace Commerz\Inventory;

use Commerz\Attributes\Api;
use Commerz\Inventory\Common\Internal\InventoryConfig;
use Commerz\Inventory\Internal\InventoryPlugin;
use Commerz\Plugins\CommerzPlugins;
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
        }
    }
}
