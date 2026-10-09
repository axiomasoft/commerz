<?php

declare(strict_types=1);

namespace Commerz\Promotions\Tests\Fixtures;

use Commerz\Plugins\Contracts\CommerzPlugin;
use Commerz\Plugins\PluginManifest;
use Commerz\Plugins\PluginRegistrar;
use Commerz\Promotions\Internal\PromotionsPlugin;
use Commerz\Promotions\Rules\Contracts\PromotionCondition;
use Commerz\Promotions\Rules\Contracts\PromotionEffect;

/** Contributes a versioned condition and an effect to the promotions' kinds. */
final class FixturePlugin implements CommerzPlugin
{
    public function manifest(): PluginManifest
    {
        return new PluginManifest('test.promotions-fixture', '1.0.0', [PromotionsPlugin::ID]);
    }

    public function register(PluginRegistrar $registrar): void
    {
        $registrar->contribute(PromotionCondition::KIND, MinItemsCondition::class);
        $registrar->contribute(PromotionEffect::KIND, FlatOffEffect::class);
        $registrar->contribute(PromotionEffect::KIND, NewerEffect::class);
    }
}
