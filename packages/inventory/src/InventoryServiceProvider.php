<?php

declare(strict_types=1);

namespace Commerz\Inventory;

use Commerz\Attributes\Api;
use Illuminate\Support\ServiceProvider;

#[Api(since: '0.1')]
final class InventoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/commerz-inventory.php', 'commerz-inventory');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/commerz-inventory.php' => config_path('commerz-inventory.php'),
            ], 'commerz-inventory-config');
        }
    }
}
