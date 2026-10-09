<?php

declare(strict_types=1);

namespace Commerz\Shipping\Tests\Feature;

use Commerz\CommerzServiceProvider;
use Commerz\PluginGraph;
use Commerz\Plugins\Contracts\PluginInspector;
use Commerz\Shipping\Carriers\Contracts\Carrier;
use Commerz\Shipping\Internal\ShippingPlugin;
use Commerz\Shipping\Rates\Contracts\DeliveryRate;
use Commerz\Shipping\ShippingServiceProvider;
use Commerz\Testkit\PackageTestCase;
use Commerz\Testkit\PluginContractTests;
use PHPUnit\Framework\Attributes\Test;

final class ShippingPluginTest extends PackageTestCase
{
    use PluginContractTests;

    protected function commerzProviders(): array
    {
        return [CommerzServiceProvider::class, ShippingServiceProvider::class];
    }

    protected function pluginClass(): string
    {
        return ShippingPlugin::class;
    }

    #[Test]
    public function declares_the_delivery_rate_and_carrier_kinds(): void
    {
        $registry = PluginGraph::compile(app(), [ShippingPlugin::class]);

        self::assertInstanceOf(PluginInspector::class, $registry);

        $kinds = [];

        foreach ($registry->describe() as $plugin) {
            if ($plugin->class === ShippingPlugin::class) {
                foreach ($plugin->kinds as $kind) {
                    $kinds[$kind->name] = $kind->interface;
                }
            }
        }

        self::assertSame([
            'delivery_rate' => DeliveryRate::class,
            'carrier' => Carrier::class,
        ], $kinds);
    }
}
