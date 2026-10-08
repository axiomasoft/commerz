<?php

declare(strict_types=1);

namespace Commerz\Shipping;

use Illuminate\Support\ServiceProvider;

final class ShippingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/commerz-shipping.php', 'commerz-shipping');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/commerz-shipping.php' => config_path('commerz-shipping.php'),
            ], 'commerz-shipping-config');
        }
    }
}
