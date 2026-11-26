<?php

declare(strict_types=1);

namespace Commerz\Shipping\Plugins;

use Commerz\Plugins\CorePlugin;
use Commerz\Ports\Delivery\Contracts\PickupPointProvider;
use Commerz\Quotes\Contracts\QuoteStage;
use Commerz\Shipping\Carriers\Contracts\Carrier;
use Commerz\Shipping\Carriers\Internal\ManualCarrier;
use Commerz\Shipping\Configuration\ShippingOptions;
use Commerz\Shipping\Diagnostics\ShippingDoctorCheck;
use Commerz\Shipping\Errors\ShippingProblemDefinitions;
use Commerz\Shipping\Options\Internal\DeliveryStage;
use Commerz\Shipping\Packing\Contracts\ParcelPacker;
use Commerz\Shipping\Packing\Internal\WeightAndVolumeParcelPacker;
use Commerz\Shipping\Panels\CarriersPanel;
use Commerz\Shipping\Panels\ShippingMethodsPanel;
use Commerz\Shipping\Panels\ShippingZonesPanel;
use Commerz\Shipping\PickupPoints\Internal\DatabasePickupPointProvider;
use Commerz\Shipping\Rates\Contracts\DeliveryRate;
use Commerz\Shipping\Rates\Internal\FlatRate;
use Commerz\Shipping\Rates\Internal\WeightTableRate;
use Commerz\Shipping\Schema\ShippingSchemaSets;
use Commerz\Shipping\ShippingServiceProvider;
use Override;
use Packman\Attributes\Api;
use Packman\Extensions\Concerns\Composition\ProvidesPanels;
use Packman\Extensions\Concerns\Configuration\Configurable;
use Packman\Extensions\Concerns\Persistence\OwnsSchema;
use Packman\Extensions\Concerns\Runtime\ProvidesDiagnostics;
use Packman\Extensions\Contracts\Composition\WithPanels;
use Packman\Extensions\Contracts\Configuration\WithConfiguration;
use Packman\Extensions\Contracts\Persistence\WithSchema;
use Packman\Extensions\Contracts\Runtime\WithDiagnostics;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Packman\Plugins\Data\Contributions\ContributionKind;
use Packman\Plugins\Data\Plugin\PluginManifest;
use Packman\Plugins\Laravel\PluginRegistrar;

/**
 * The shipping's code plugin: declares `delivery_rate` and `carrier`, contributes the built-in `flat` and
 * `weight_table` rates, the `manual` carrier, and the `delivery` quote stage, and exposes shipping configuration,
 * schema, diagnostics, and panels.
 */
#[Api(since: '0.1')]
final class ShippingPlugin implements ExtensionPlugin, WithConfiguration, WithDiagnostics, WithPanels, WithSchema
{
    use Configurable;
    use OwnsSchema;
    use ProvidesDiagnostics;
    use ProvidesPanels;

    public const string ID = 'commerz.shipping';

    #[Override]
    public function manifest(): PluginManifest
    {
        return new PluginManifest(
            id: self::ID,
            version: '0.1.0',
            requires: [CorePlugin::ID],
            resourceRoot: dirname(__DIR__, 2),
            databases: ['pgsql'],
        );
    }

    #[Override]
    public function register(PluginRegistrar $registrar): void
    {
        $registrar->declareKind(new ContributionKind(DeliveryRate::KIND, DeliveryRate::class, self::ID));
        $registrar->declareKind(new ContributionKind(Carrier::KIND, Carrier::class, self::ID));
        $registrar->declareKind(new ContributionKind(ParcelPacker::KIND, ParcelPacker::class, self::ID));
        $registrar->contribute(DeliveryRate::KIND, FlatRate::class);
        $registrar->contribute(DeliveryRate::KIND, WeightTableRate::class);
        $registrar->contribute(Carrier::KIND, ManualCarrier::class);
        $registrar->contribute(ParcelPacker::KIND, WeightAndVolumeParcelPacker::class);
        $registrar->contribute(PickupPointProvider::KIND, DatabasePickupPointProvider::class);
        $registrar->contribute(QuoteStage::KIND, DeliveryStage::class);
    }

    #[Override]
    public function configRoot(): string
    {
        return 'commerz-shipping';
    }

    #[Override]
    public function defaultConfigPath(): string
    {
        return dirname(__DIR__, 2).'/config/commerz-shipping.php';
    }

    /** @return class-string<ShippingOptions> */
    #[Override]
    public function optionsClass(): string
    {
        return ShippingOptions::class;
    }

    #[Override]
    public function tablePrefix(): string
    {
        return 'cmz_';
    }

    /** @return list<string> */
    #[Override]
    public function tableNouns(): array
    {
        return ShippingServiceProvider::NOUNS;
    }

    #[Override]
    public function migrationPath(): string
    {
        return dirname(__DIR__, 2).'/database/migrations';
    }

    /** @return list<class-string> */
    #[Override]
    public function schemaSetProviders(): array
    {
        return [ShippingSchemaSets::class];
    }

    /** @return list<class-string> */
    #[Override]
    public function healthChecks(): array
    {
        return [ShippingDoctorCheck::class];
    }

    /** @return list<class-string> */
    #[Override]
    public function problemProviders(): array
    {
        return [ShippingProblemDefinitions::class];
    }

    /** @return list<class-string> */
    #[Override]
    public function panelProviders(): array
    {
        return [ShippingZonesPanel::class, ShippingMethodsPanel::class, CarriersPanel::class];
    }
}
