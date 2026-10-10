<?php

declare(strict_types=1);

namespace Commerz\Sales\Tests\Fixtures;

use Commerz\Plugins\Contracts\CommerzPlugin;
use Commerz\Plugins\PluginManifest;
use Commerz\Plugins\PluginRegistrar;
use Commerz\Sales\Internal\SalesPlugin;
use Commerz\Sales\Numbering\Contracts\OrderNumberGenerator;

/**
 * The sellables of the static registry, the colliding number generator, and stages standing in for the promotions
 * (`promo:{id}`), shipping (`flat:{price}`) and tax (`vat`) packages.
 */
final class SalesFixturePlugin implements CommerzPlugin
{
    public function manifest(): PluginManifest
    {
        return new PluginManifest('tests.sales', '1.0.0', [SalesPlugin::ID]);
    }

    public function register(PluginRegistrar $registrar): void
    {
        $registrar->sellableProvider(StaticSellables::class);
        $registrar->contribute(OrderNumberGenerator::KIND, DuplicateNumbers::class);
        $registrar->quoteStage(PromotionAdjustmentStage::class);
        $registrar->quoteStage(FlatDeliveryStage::class);
        $registrar->quoteStage(VatStage::class);
    }
}
