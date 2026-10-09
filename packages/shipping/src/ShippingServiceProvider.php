<?php

declare(strict_types=1);

namespace Commerz\Shipping;

use Commerz\Attributes\Api;
use Commerz\Plugins\CommerzPlugins;
use Commerz\Ports\Delivery\Contracts\DeliveryOptions;
use Commerz\Ports\Delivery\Contracts\ShipmentBooking;
use Commerz\Schema\Contracts\SchemaOwnership;
use Commerz\Shipping\Bookings\Contracts\Bookings;
use Commerz\Shipping\Bookings\Internal\CarrierShipmentBooking;
use Commerz\Shipping\Bookings\Internal\DatabaseBookings;
use Commerz\Shipping\Common\Internal\ShippingConfig;
use Commerz\Shipping\Internal\ShippingPlugin;
use Commerz\Shipping\Methods\Contracts\Methods;
use Commerz\Shipping\Methods\Internal\DatabaseMethods;
use Commerz\Shipping\Options\Contracts\ShippingOptions;
use Commerz\Shipping\Options\Internal\DatabaseShippingOptions;
use Commerz\Shipping\Zones\Contracts\Zones;
use Commerz\Shipping\Zones\Internal\DatabaseZones;
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

        $this->app->singleton(Zones::class, DatabaseZones::class);
        $this->app->singleton(Methods::class, DatabaseMethods::class);
        $this->app->singleton(ShippingOptions::class, DatabaseShippingOptions::class);
        $this->app->singleton(Bookings::class, DatabaseBookings::class);
        // Plain singletons replace the kernel's `singletonIf` NoDelivery defaults.
        $this->app->singleton(DeliveryOptions::class, DatabaseShippingOptions::class);
        $this->app->singleton(ShipmentBooking::class, CarrierShipmentBooking::class);
        $this->app->singleton(Shipping::class);

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
