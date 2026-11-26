<?php

declare(strict_types=1);

namespace Commerz\Laravel\Registrars;

use Commerz\Configuration\CommerzOptions;
use Commerz\Configuration\StockMode;
use Commerz\Ports\Assets\Contracts\AssetResolverPort;
use Commerz\Ports\Assets\Internal\NullAssetResolver;
use Commerz\Ports\Customers\Contracts\CustomerDirectory;
use Commerz\Ports\Customers\Internal\GuestOnlyDirectory;
use Commerz\Ports\Delivery\Contracts\DeliveryOptions;
use Commerz\Ports\Delivery\Contracts\ShipmentBooking;
use Commerz\Ports\Delivery\Internal\NoDelivery;
use Commerz\Ports\Digital\Contracts\DigitalDeliveryPort;
use Commerz\Ports\Digital\Internal\NullDigitalDelivery;
use Commerz\Ports\Prices\Contracts\PriceResolver;
use Commerz\Ports\Prices\Internal\SnapshotPriceResolver;
use Commerz\Ports\Privacy\Contracts\PiiCipherPort;
use Commerz\Ports\Privacy\Internal\NullPiiCipher;
use Commerz\Ports\Promotions\Contracts\PromotionUsage;
use Commerz\Ports\Promotions\Internal\NullPromotionUsage;
use Commerz\Ports\Stock\Contracts\StockAvailability;
use Commerz\Ports\Stock\Contracts\StockReservations;
use Commerz\Ports\Stock\Contracts\StockReturns;
use Commerz\Ports\Stock\Internal\UnlimitedStock;
use Commerz\Ports\StoredValue\Contracts\StoredValueTender;
use Commerz\Ports\StoredValue\Internal\NullStoredValueTender;
use Illuminate\Contracts\Foundation\Application;
use Override;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Config\Exceptions\InvalidConfiguration;
use Packman\Foundation\Concerns\RegistersNothingOnBoot;
use Packman\Foundation\Contracts\PackageRegistrar;

/**
 * Registers default core implementations of cross-package Commerz ports unless overridden by feature packages.
 */
#[Spi(kind: SpiKind::Call, since: '0.1')]
final class CommerzPortsRegistrar implements PackageRegistrar
{
    use RegistersNothingOnBoot;

    #[Override]
    public function register(Application $app): void
    {
        $app->singletonIf(AssetResolverPort::class, NullAssetResolver::class);
        $app->singletonIf(DigitalDeliveryPort::class, NullDigitalDelivery::class);
        $app->singletonIf(PiiCipherPort::class, NullPiiCipher::class);
        $app->singletonIf(PriceResolver::class, SnapshotPriceResolver::class);
        $app->singletonIf(PromotionUsage::class, NullPromotionUsage::class);
        $app->singletonIf(StoredValueTender::class, NullStoredValueTender::class);
        $app->singletonIf(DeliveryOptions::class, NoDelivery::class);
        $app->singletonIf(ShipmentBooking::class, NoDelivery::class);

        $unlimited = static function (Application $app): UnlimitedStock {
            if ($app->make(CommerzOptions::class)->stock === StockMode::Inventory) {
                throw InvalidConfiguration::fromProblems([
                    "commerz.stock: 'inventory' needs a package implementing StockAvailability, StockReservations and StockReturns (axiomasoft/commerz-inventory); install it or set 'unlimited'",
                ]);
            }

            return new UnlimitedStock;
        };

        $app->singletonIf(StockAvailability::class, $unlimited);
        $app->singletonIf(StockReservations::class, $unlimited);
        $app->singletonIf(StockReturns::class, $unlimited);

        $app->singletonIf(CustomerDirectory::class, static function (Application $app): CustomerDirectory {
            $class = $app->make(CommerzOptions::class)->customers;

            if ($class === null) {
                return new GuestOnlyDirectory;
            }

            $directory = $app->make($class);

            if (! $directory instanceof CustomerDirectory) {
                throw InvalidConfiguration::fromProblems([
                    "commerz.customers: {$class} does not implement ".CustomerDirectory::class,
                ]);
            }

            return $directory;
        });
    }
}
