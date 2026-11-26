<?php

declare(strict_types=1);

namespace Commerz\Shipping\Laravel\Registrars;

use Commerz\Ports\Delivery\Contracts\DeliveryOptions;
use Commerz\Ports\Delivery\Contracts\ShipmentBooking;
use Commerz\Ports\Privacy\Events\CustomerPersonalDataErased;
use Commerz\Schema\CommerzSchemaSets;
use Commerz\Shipping\Bookings\Contracts\Bookings;
use Commerz\Shipping\Bookings\Internal\CarrierShipmentBooking;
use Commerz\Shipping\Bookings\Internal\DatabaseBookings;
use Commerz\Shipping\Configuration\ShippingOptions;
use Commerz\Shipping\Diagnostics\ShippingDoctorCheck;
use Commerz\Shipping\Methods\Contracts\Methods;
use Commerz\Shipping\Methods\Internal\DatabaseMethods;
use Commerz\Shipping\Options\Contracts\ShippingOptions as ShippingOptionsContract;
use Commerz\Shipping\Options\Internal\DatabaseShippingOptions;
use Commerz\Shipping\Plugins\ShippingPlugin;
use Commerz\Shipping\Shipping;
use Commerz\Shipping\Zones\Contracts\Zones;
use Commerz\Shipping\Zones\Internal\DatabaseZones;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Override;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Foundation\Contracts\PackageRegistrar;
use Packman\Plugins\Contracts\Contributions\Contributions;

/**
 * Wires Commerz shipping services, extension plugin, options, schema ownership, diagnostics, and panels.
 */
#[Spi(kind: SpiKind::Call, since: '0.1')]
final class ShippingRegistrar implements PackageRegistrar
{
    #[Override]
    public function register(Application $app): void
    {
        $app->singletonIf(ShippingPlugin::class);

        $app->singleton(ShippingDoctorCheck::class, static fn (Application $app): ShippingDoctorCheck => new ShippingDoctorCheck(
            config: $app->make(ConfigRepository::class),
            optionsResolver: static fn (): ShippingOptions => $app->make(ShippingOptions::class),
            contributionsResolver: static fn (): Contributions => $app->make(Contributions::class),
        ));

        CommerzSchemaSets::governMigrations($app);

        $app->singleton(Zones::class, DatabaseZones::class);
        $app->singleton(Methods::class, DatabaseMethods::class);
        $app->singleton(ShippingOptionsContract::class, DatabaseShippingOptions::class);
        $app->singleton(Bookings::class, DatabaseBookings::class);
        // Plain singletons replace the kernel's `singletonIf` NoDelivery defaults.
        $app->singleton(DeliveryOptions::class, DatabaseShippingOptions::class);
        $app->singleton(ShipmentBooking::class, CarrierShipmentBooking::class);
        $app->singleton(Shipping::class);
    }

    #[Override]
    public function boot(Application $app): void
    {
        $app->make(Dispatcher::class)->listen(CustomerPersonalDataErased::class, static function (CustomerPersonalDataErased $event) use ($app): void {
            $app->make(Bookings::class)->erasePersonalData($event->bookingReferences);
        });
    }
}
