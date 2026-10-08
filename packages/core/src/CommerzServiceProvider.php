<?php

declare(strict_types=1);

namespace Commerz;

use Illuminate\Support\ServiceProvider;

final class CommerzServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/commerz.php', 'commerz');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/commerz.php' => config_path('commerz.php'),
            ], 'commerz-config');
        }
    }
}
