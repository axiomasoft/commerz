<?php

declare(strict_types=1);

namespace Commerz\Promotions\Internal;

use Commerz\Plugins\Contracts\CommerzPlugin;
use Commerz\Plugins\ContributionKind;
use Commerz\Plugins\PluginManifest;
use Commerz\Plugins\PluginRegistrar;
use Commerz\Promotions\Coupons\Internal\CouponActivator;
use Commerz\Promotions\Rules\Contracts\PromotionActivator;
use Commerz\Promotions\Rules\Contracts\PromotionCondition;
use Commerz\Promotions\Rules\Contracts\PromotionEffect;
use Commerz\Promotions\Rules\Internal\Activators\AutomaticActivator;
use Commerz\Promotions\Rules\Internal\Conditions\CartQuantityAtLeast;
use Commerz\Promotions\Rules\Internal\Conditions\CartSubtotalAtLeast;
use Commerz\Promotions\Rules\Internal\Conditions\CustomerInGroup;
use Commerz\Promotions\Rules\Internal\Conditions\LineCategoryIn;
use Commerz\Promotions\Rules\Internal\Conditions\LineSellableIn;
use Commerz\Promotions\Rules\Internal\Effects\BuyXGetY;
use Commerz\Promotions\Rules\Internal\Effects\DeliveryPercentOff;
use Commerz\Promotions\Rules\Internal\Effects\LineFixedOff;
use Commerz\Promotions\Rules\Internal\Effects\LinePercentOff;
use Commerz\Promotions\Rules\Internal\Effects\OrderFixedOff;
use Commerz\Promotions\Rules\Internal\Effects\OrderPercentOff;

/**
 * The promotions' own plugin: declares the `promotion_condition`, `promotion_effect` and `promotion_activator` kinds and
 * contributes the built-in rules and the `automatic` and `coupon` activators through them, as a third-party plugin
 * would. The quote stages are contributed as they are added.
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

        foreach ([LineSellableIn::class, LineCategoryIn::class, CustomerInGroup::class, CartSubtotalAtLeast::class, CartQuantityAtLeast::class] as $condition) {
            $registrar->contribute(PromotionCondition::KIND, $condition);
        }

        foreach ([LinePercentOff::class, LineFixedOff::class, OrderPercentOff::class, OrderFixedOff::class, DeliveryPercentOff::class, BuyXGetY::class] as $effect) {
            $registrar->contribute(PromotionEffect::KIND, $effect);
        }

        $registrar->contribute(PromotionActivator::KIND, AutomaticActivator::class);
        $registrar->contribute(PromotionActivator::KIND, CouponActivator::class);
    }
}
