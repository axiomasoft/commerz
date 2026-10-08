<?php

declare(strict_types=1);

namespace Commerz\Sales;

use Illuminate\Support\ServiceProvider;

final class SalesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/commerz-sales.php', 'commerz-sales');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/commerz-sales.php' => config_path('commerz-sales.php'),
            ], 'commerz-sales-config');
        }
    }
}
