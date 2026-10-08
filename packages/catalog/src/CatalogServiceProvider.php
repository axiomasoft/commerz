<?php

declare(strict_types=1);

namespace Commerz\Catalog;

use Illuminate\Support\ServiceProvider;

final class CatalogServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/commerz-catalog.php', 'commerz-catalog');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/commerz-catalog.php' => config_path('commerz-catalog.php'),
            ], 'commerz-catalog-config');
        }
    }
}
