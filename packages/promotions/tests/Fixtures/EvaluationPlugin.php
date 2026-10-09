<?php

declare(strict_types=1);

namespace Commerz\Promotions\Tests\Fixtures;

use Commerz\Plugins\Contracts\CommerzPlugin;
use Commerz\Plugins\PluginManifest;
use Commerz\Plugins\PluginRegistrar;
use Commerz\Promotions\Internal\PromotionsPlugin;
use Commerz\Promotions\Rules\Contracts\PromotionActivator;
use Commerz\Promotions\Rules\Contracts\PromotionCondition;
use Commerz\Promotions\Rules\Contracts\PromotionEffect;

/** What the engine tests need around the promotion stages: a delivery charge, and rules and an activator that misbehave. */
final class EvaluationPlugin implements CommerzPlugin
{
    public function manifest(): PluginManifest
    {
        return new PluginManifest('test.promotions-evaluation', '1.0.0', [PromotionsPlugin::ID]);
    }

    public function register(PluginRegistrar $registrar): void
    {
        $registrar->quoteStage(FlatDeliveryStage::class);
        $registrar->contribute(PromotionCondition::KIND, RogueLineFilter::class);
        $registrar->contribute(PromotionEffect::KIND, RogueEffect::class);
        $registrar->contribute(PromotionActivator::KIND, RogueActivator::class);
    }
}
