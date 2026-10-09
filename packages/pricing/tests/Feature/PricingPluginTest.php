<?php

declare(strict_types=1);

namespace Commerz\Pricing\Tests\Feature;

use Commerz\CommerzServiceProvider;
use Commerz\PluginGraph;
use Commerz\Plugins\Contracts\Contributions;
use Commerz\Plugins\Contracts\PluginInspector;
use Commerz\Pricing\Calculation\Contracts\TaxCalculator;
use Commerz\Pricing\Calculation\Internal\RatesTaxCalculator;
use Commerz\Pricing\Calculation\Internal\TaxStage;
use Commerz\Pricing\Internal\PricingPlugin;
use Commerz\Pricing\PricingServiceProvider;
use Commerz\Quotes\Contracts\QuoteStage;
use Commerz\Testkit\PackageTestCase;
use Commerz\Testkit\PluginContractTests;
use PHPUnit\Framework\Attributes\Test;

final class PricingPluginTest extends PackageTestCase
{
    use PluginContractTests;

    protected function commerzProviders(): array
    {
        return [CommerzServiceProvider::class, PricingServiceProvider::class];
    }

    protected function pluginClass(): string
    {
        return PricingPlugin::class;
    }

    #[Test]
    public function declares_the_tax_calculator_kind(): void
    {
        $registry = PluginGraph::compile(app(), [PricingPlugin::class]);

        self::assertInstanceOf(PluginInspector::class, $registry);

        $kinds = [];

        foreach ($registry->describe() as $plugin) {
            if ($plugin->class === PricingPlugin::class) {
                foreach ($plugin->kinds as $kind) {
                    $kinds[$kind->name] = $kind->interface;
                }
            }
        }

        self::assertSame(['tax_calculator' => TaxCalculator::class], $kinds);
    }

    #[Test]
    public function contributes_the_rates_calculator(): void
    {
        $registry = PluginGraph::compile(app(), [PricingPlugin::class]);
        $contributions = [];

        foreach ($registry->describe() as $plugin) {
            foreach ($plugin->contributions as $contribution) {
                $contributions[$contribution->kind][] = $contribution->key;
            }
        }

        self::assertSame(['rates'], $contributions[TaxCalculator::KIND] ?? []);
        self::assertInstanceOf(RatesTaxCalculator::class, app(Contributions::class)->get(TaxCalculator::KIND, 'rates', TaxCalculator::class));
    }

    #[Test]
    public function contributes_the_tax_quote_stage(): void
    {
        $registry = PluginGraph::compile(app(), [PricingPlugin::class]);
        $stages = [];

        foreach ($registry->describe() as $plugin) {
            foreach ($plugin->contributions as $contribution) {
                if ($plugin->class === PricingPlugin::class && $contribution->kind === 'quote_stage') {
                    $stages[] = $contribution->key;
                }
            }
        }

        self::assertSame(['tax'], $stages);
        self::assertInstanceOf(TaxStage::class, app(Contributions::class)->get('quote_stage', 'tax', QuoteStage::class));
    }
}
