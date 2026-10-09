<?php

declare(strict_types=1);

namespace Commerz\Pricing;

use Commerz\Attributes\Api;
use Commerz\Plugins\CommerzPlugins;
use Commerz\Ports\Prices\Contracts\PriceResolver;
use Commerz\Pricing\Common\Internal\PricingConfig;
use Commerz\Pricing\Internal\PricingPlugin;
use Commerz\Pricing\PriceLists\Contracts\PriceLists;
use Commerz\Pricing\PriceLists\Internal\DatabasePriceLists;
use Commerz\Pricing\Prices\Contracts\Prices;
use Commerz\Pricing\Prices\Internal\DatabasePriceResolver;
use Commerz\Pricing\Prices\Internal\DatabasePrices;
use Commerz\Pricing\Prices\Internal\PriceWrites;
use Commerz\Pricing\Taxes\Contracts\Taxes;
use Commerz\Pricing\Taxes\Internal\DatabaseTaxes;
use Commerz\Pricing\Taxes\Internal\TaxRateLookup;
use Commerz\Pricing\Taxes\Internal\TaxZoneLookup;
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

        $this->app->singleton(PriceWrites::class);
        $this->app->singleton(PriceLists::class, DatabasePriceLists::class);
        // Replaces the core's snapshot default whichever provider registered first (the core binds it with `singletonIf`).
        $this->app->singleton(PriceResolver::class, DatabasePriceResolver::class);
        $this->app->singleton(Prices::class, DatabasePrices::class);
        $this->app->singleton(TaxZoneLookup::class);
        $this->app->singleton(TaxRateLookup::class);
        $this->app->singleton(Taxes::class, DatabaseTaxes::class);
        $this->app->singleton(Pricing::class);

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
