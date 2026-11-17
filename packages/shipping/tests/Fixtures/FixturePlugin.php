<?php

declare(strict_types=1);

namespace Commerz\Shipping\Tests\Fixtures;

use Commerz\Shipping\Plugins\ShippingPlugin;
use Commerz\Shipping\Rates\Contracts\DeliveryRate;
use Override;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Packman\Plugins\Data\Plugin\PluginManifest;
use Packman\Plugins\Laravel\PluginRegistrar;

/** Contributes two versioned delivery rates. */
final class FixturePlugin implements ExtensionPlugin
{
    #[Override]
    public function manifest(): PluginManifest
    {
        return new PluginManifest('test.shipping-fixture', '1.0.0', [ShippingPlugin::ID]);
    }

    #[Override]
    public function register(PluginRegistrar $registrar): void
    {
        $registrar->contribute(DeliveryRate::KIND, FixedRate::class);
        $registrar->contribute(DeliveryRate::KIND, NewerRate::class);
        $registrar->contribute(DeliveryRate::KIND, BrokenLoadingRate::class);
    }
}
