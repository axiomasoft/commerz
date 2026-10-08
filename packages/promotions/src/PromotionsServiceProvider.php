<?php

declare(strict_types=1);

namespace Commerz\Promotions;

use Illuminate\Support\ServiceProvider;

final class PromotionsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/commerz-promotions.php', 'commerz-promotions');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/commerz-promotions.php' => config_path('commerz-promotions.php'),
            ], 'commerz-promotions-config');
        }
    }
}
