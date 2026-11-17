<?php

declare(strict_types=1);

namespace Commerz\Promotions\Tests\Fixtures;

use Commerz\Plugins\CorePlugin;
use Commerz\Promotions\Plugins\PromotionsPlugin;
use Commerz\Promotions\Rules\Contracts\PromotionActivator;
use Commerz\Promotions\Rules\Contracts\PromotionCondition;
use Commerz\Promotions\Rules\Contracts\PromotionEffect;
use Commerz\Quotes\Contracts\QuoteStage;
use Override;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Packman\Plugins\Data\Plugin\PluginManifest;
use Packman\Plugins\Laravel\PluginRegistrar;

/** What the engine tests need around the promotion stages: a delivery charge, and rules and an activator that misbehave. */
final class EvaluationPlugin implements ExtensionPlugin
{
    #[Override]
    public function manifest(): PluginManifest
    {
        return new PluginManifest('test.promotions-evaluation', '1.0.0', [CorePlugin::ID, PromotionsPlugin::ID]);
    }

    #[Override]
    public function register(PluginRegistrar $registrar): void
    {
        $registrar->contribute(QuoteStage::KIND, FlatDeliveryStage::class);
        $registrar->contribute(PromotionCondition::KIND, RogueLineFilter::class);
        $registrar->contribute(PromotionEffect::KIND, RogueEffect::class);
        $registrar->contribute(PromotionActivator::KIND, RogueActivator::class);
    }
}
