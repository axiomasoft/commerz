<?php

declare(strict_types=1);

namespace Commerz\Pricing;

use Illuminate\Support\ServiceProvider;

final class PricingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/commerz-pricing.php', 'commerz-pricing');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/commerz-pricing.php' => config_path('commerz-pricing.php'),
            ], 'commerz-pricing-config');
        }
    }
}
