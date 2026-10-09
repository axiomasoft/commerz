<?php

declare(strict_types=1);

namespace Commerz\Promotions\Internal;

use Commerz\Plugins\Contracts\CommerzPlugin;
use Commerz\Plugins\ContributionKind;
use Commerz\Plugins\PluginManifest;
use Commerz\Plugins\PluginRegistrar;
use Commerz\Promotions\Rules\Contracts\PromotionActivator;
use Commerz\Promotions\Rules\Contracts\PromotionCondition;
use Commerz\Promotions\Rules\Contracts\PromotionEffect;

/**
 * The promotions' own plugin: declares the `promotion_condition`, `promotion_effect` and `promotion_activator` kinds.
 * The built-in rules, the activators and the quote stages are contributed as they are added.
 */
final class PromotionsPlugin implements CommerzPlugin
{
    public const string ID = 'commerz.promotions';

    public function manifest(): PluginManifest
    {
        return new PluginManifest(self::ID, '0.1.0');
    }

    public function register(PluginRegistrar $registrar): void
    {
        $registrar->declareKind(new ContributionKind(PromotionCondition::KIND, PromotionCondition::class, self::ID));
        $registrar->declareKind(new ContributionKind(PromotionEffect::KIND, PromotionEffect::class, self::ID));
        $registrar->declareKind(new ContributionKind(PromotionActivator::KIND, PromotionActivator::class, self::ID));
    }
}
