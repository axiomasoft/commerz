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
use Commerz\Quotes\Contracts\QuoteStage;
use Commerz\Quotes\Enums\StagePosition;
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

    #[Test]
    public function contributes_the_automatic_and_coupon_activators(): void
    {
        $contributed = [];

        foreach (PluginGraph::compile(app(), [PromotionsPlugin::class])->describe() as $plugin) {
            foreach ($plugin->contributions as $contribution) {
                if ($contribution->kind === PromotionActivator::KIND) {
                    $contributed[] = $contribution->key;
                }
            }
        }

        sort($contributed);

        self::assertSame(['automatic', 'coupon'], $contributed);
    }

    #[Test]
    public function adds_the_three_promotion_stages_at_their_anchors(): void
    {
        $registry = PluginGraph::compile(app(), [PromotionsPlugin::class]);
        $stages = [];

        foreach ($registry->describe() as $plugin) {
            foreach ($plugin->contributions as $contribution) {
                if ($plugin->class === PromotionsPlugin::class && $contribution->kind === QuoteStage::KIND) {
                    $stages[] = $contribution->key;
                }
            }
        }

        self::assertSame(['promotions.catalog', 'promotions.cart', 'promotions.delivery'], $stages);

        foreach ($stages as $key) {
            $stage = $registry->get(QuoteStage::KIND, $key, QuoteStage::class);

            self::assertSame($key, $stage->anchor()->value);
            self::assertSame(StagePosition::At, $stage->position());
        }
    }
}
