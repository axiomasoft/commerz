<?php

declare(strict_types=1);

namespace Commerz\Shipping\Tests\Fixtures;

use Commerz\Plugins\Contracts\CommerzPlugin;
use Commerz\Plugins\PluginManifest;
use Commerz\Plugins\PluginRegistrar;
use Commerz\Shipping\Internal\ShippingPlugin;
use Commerz\Shipping\Rates\Contracts\DeliveryRate;

/** Contributes two versioned delivery rates. */
final class FixturePlugin implements CommerzPlugin
{
    public function manifest(): PluginManifest
    {
        return new PluginManifest('test.shipping-fixture', '1.0.0', [ShippingPlugin::ID]);
    }

    public function register(PluginRegistrar $registrar): void
    {
        $registrar->contribute(DeliveryRate::KIND, FixedRate::class);
        $registrar->contribute(DeliveryRate::KIND, NewerRate::class);
    }
}
