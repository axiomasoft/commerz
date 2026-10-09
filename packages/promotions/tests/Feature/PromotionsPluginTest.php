<?php

declare(strict_types=1);

namespace Commerz\Promotions\Tests\Feature;

use Commerz\CommerzServiceProvider;
use Commerz\PluginGraph;
use Commerz\Plugins\Contracts\PluginInspector;
use Commerz\Promotions\Internal\PromotionsPlugin;
use Commerz\Promotions\PromotionsServiceProvider;
use Commerz\Promotions\Rules\Contracts\PromotionActivator;
use Commerz\Promotions\Rules\Contracts\PromotionCondition;
use Commerz\Promotions\Rules\Contracts\PromotionEffect;
use Commerz\Testkit\PackageTestCase;
use Commerz\Testkit\PluginContractTests;
use PHPUnit\Framework\Attributes\Test;

final class PromotionsPluginTest extends PackageTestCase
{
    use PluginContractTests;

    protected function commerzProviders(): array
    {
        return [CommerzServiceProvider::class, PromotionsServiceProvider::class];
    }

    protected function pluginClass(): string
    {
        return PromotionsPlugin::class;
    }

    #[Test]
    public function declares_the_condition_effect_and_activator_kinds(): void
    {
        $registry = PluginGraph::compile(app(), [PromotionsPlugin::class]);

        self::assertInstanceOf(PluginInspector::class, $registry);

        $kinds = [];

        foreach ($registry->describe() as $plugin) {
            if ($plugin->class === PromotionsPlugin::class) {
                foreach ($plugin->kinds as $kind) {
                    $kinds[$kind->name] = $kind->interface;
                }
            }
        }

        self::assertSame([
            'promotion_condition' => PromotionCondition::class,
            'promotion_effect' => PromotionEffect::class,
            'promotion_activator' => PromotionActivator::class,
        ], $kinds);
    }
}
