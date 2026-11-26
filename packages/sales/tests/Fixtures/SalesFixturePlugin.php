<?php

declare(strict_types=1);

namespace Commerz\Sales\Tests\Fixtures;

use Commerz\Plugins\CorePlugin;
use Commerz\Ports\Sellables\Contracts\SellableProvider;
use Commerz\Quotes\Contracts\QuoteStage;
use Commerz\Sales\Gateways\Contracts\PaymentGateway;
use Commerz\Sales\Numbering\Contracts\OrderNumberGenerator;
use Commerz\Sales\Plugins\SalesPlugin;
use Override;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Packman\Plugins\Data\Plugin\PluginManifest;
use Packman\Plugins\Laravel\PluginRegistrar;

/**
 * The sellables of the static registry, the recording gateway, the colliding number generator, and stages standing in for the promotions
 * (`promo:{id}`), shipping (`flat:{price}`) and tax (`vat`) packages.
 */
final class SalesFixturePlugin implements ExtensionPlugin
{
    #[Override]
    public function manifest(): PluginManifest
    {
        return new PluginManifest('tests.sales', '1.0.0', [CorePlugin::ID, SalesPlugin::ID]);
    }

    #[Override]
    public function register(PluginRegistrar $registrar): void
    {
        $registrar->contribute(SellableProvider::KIND, StaticSellables::class);
        $registrar->contribute(PaymentGateway::KIND, RecordingGateway::class);
        $registrar->contribute(PaymentGateway::KIND, AcquiringRecordingGateway::class);
        $registrar->contribute(OrderNumberGenerator::KIND, DuplicateNumbers::class);
        $registrar->contribute(QuoteStage::KIND, PromotionAdjustmentStage::class);
        $registrar->contribute(QuoteStage::KIND, FlatDeliveryStage::class);
        $registrar->contribute(QuoteStage::KIND, VatStage::class);
    }
}
