<?php

declare(strict_types=1);

namespace Commerz\Pricing;

use Commerz\Attributes\Api;
use Commerz\Plugins\CommerzPlugins;
use Commerz\Pricing\Common\Internal\PricingConfig;
use Commerz\Pricing\Internal\PricingPlugin;
use Commerz\Schema\Contracts\SchemaOwnership;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

#[Api(since: '0.1')]
final class PricingServiceProvider extends ServiceProvider
{
    public const array NOUNS = ['price', 'tax'];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/commerz-pricing.php', 'commerz-pricing');

        $this->app->singleton(PricingConfig::class, static function (Application $app): PricingConfig {
            $config = $app->make(Repository::class)->get('commerz-pricing', []);

            return PricingConfig::fromArray(is_array($config) ? $config : []);
        });

        CommerzPlugins::builtIn(PricingPlugin::class);

        $this->callAfterResolving(SchemaOwnership::class, static function (SchemaOwnership $ownership): void {
            $ownership->declare('commerz.pricing', self::NOUNS);
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/commerz-pricing.php' => config_path('commerz-pricing.php'),
            ], 'commerz-pricing-config');
        }
    }
}
