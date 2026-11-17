<?php

declare(strict_types=1);

namespace Commerz\Sales\Tests\Feature;

use Commerz\CommerzServiceProvider;
use Commerz\Plugins\CorePlugin;
use Commerz\Sales\Gateways\Contracts\PaymentGateway;
use Commerz\Sales\Gateways\Internal\ManualGateway;
use Commerz\Sales\Numbering\Contracts\OrderNumberGenerator;
use Commerz\Sales\Numbering\Internal\SequenceOrderNumbers;
use Commerz\Sales\Plugins\SalesPlugin;
use Commerz\Sales\SalesServiceProvider;
use Commerz\Testkit\PackageTestCase;
use Commerz\Testkit\PluginContractTests;
use Packman\Plugins\Contracts\Contributions\Contributions;
use Packman\Plugins\Contracts\Registry\PluginInspector;
use Packman\Plugins\Testing\PluginGraph;
use PHPUnit\Framework\Attributes\Test;

final class SalesPluginTest extends PackageTestCase
{
    use PluginContractTests;

    protected function commerzProviders(): array
    {
        return [CommerzServiceProvider::class, SalesServiceProvider::class];
    }

    protected function pluginClass(): string
    {
        return SalesPlugin::class;
    }

    protected function expectedContributions(): array
    {
        return [
            'order_number_generator' => ['sequence'],
            'payment_gateway' => ['manual'],
        ];
    }

    #[Test]
    public function declares_the_payment_gateway_and_order_number_generator_kinds(): void
    {
        $registry = PluginGraph::compile(app(), [CorePlugin::class, SalesPlugin::class]);

        self::assertInstanceOf(PluginInspector::class, $registry);

        $kinds = [];

        foreach ($registry->describe() as $plugin) {
            if ($plugin->class === SalesPlugin::class) {
                foreach ($plugin->kinds as $kind) {
                    $kinds[$kind->name] = $kind->interface;
                }
            }
        }

        self::assertSame([
            'payment_gateway' => PaymentGateway::class,
            'order_number_generator' => OrderNumberGenerator::class,
        ], $kinds);
    }

    #[Test]
    public function the_built_in_manual_gateway_and_sequence_numbering_are_registered(): void
    {
        $contributions = app(Contributions::class);

        self::assertInstanceOf(ManualGateway::class, $contributions->get(PaymentGateway::KIND, 'manual', PaymentGateway::class));
        self::assertInstanceOf(SequenceOrderNumbers::class, $contributions->get(OrderNumberGenerator::KIND, 'sequence', OrderNumberGenerator::class));
    }
}
