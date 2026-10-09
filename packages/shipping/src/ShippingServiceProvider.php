<?php

declare(strict_types=1);

namespace Commerz\Shipping;

use Commerz\Attributes\Api;
use Commerz\Plugins\CommerzPlugins;
use Commerz\Schema\Contracts\SchemaOwnership;
use Commerz\Shipping\Common\Internal\ShippingConfig;
use Commerz\Shipping\Internal\ShippingPlugin;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

#[Api(since: '0.1')]
final class ShippingServiceProvider extends ServiceProvider
{
    public const array NOUNS = ['shipping'];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/commerz-shipping.php', 'commerz-shipping');

        $this->app->singleton(ShippingConfig::class, static function (Application $app): ShippingConfig {
            $config = $app->make(Repository::class)->get('commerz-shipping', []);

            return ShippingConfig::fromArray(is_array($config) ? $config : []);
        });

        CommerzPlugins::builtIn(ShippingPlugin::class);

        $this->callAfterResolving(SchemaOwnership::class, static function (SchemaOwnership $ownership): void {
            $ownership->declare('commerz.shipping', self::NOUNS);
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/commerz-shipping.php' => config_path('commerz-shipping.php'),
            ], 'commerz-shipping-config');
        }
    }
}
