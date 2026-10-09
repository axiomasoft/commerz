<?php

declare(strict_types=1);

namespace Commerz\Shipping\Internal;

use Commerz\Plugins\Contracts\CommerzPlugin;
use Commerz\Plugins\ContributionKind;
use Commerz\Plugins\PluginManifest;
use Commerz\Plugins\PluginRegistrar;
use Commerz\Shipping\Carriers\Contracts\Carrier;
use Commerz\Shipping\Carriers\Internal\ManualCarrier;
use Commerz\Shipping\Options\Internal\DeliveryStage;
use Commerz\Shipping\Rates\Contracts\DeliveryRate;
use Commerz\Shipping\Rates\Internal\FlatRate;
use Commerz\Shipping\Rates\Internal\WeightTableRate;

/** The shipping's own plugin: declares the `delivery_rate` and `carrier` kinds and contributes the built-in `flat` and `weight_table` rates, the `manual` carrier and the `delivery` quote stage. */
final class ShippingPlugin implements CommerzPlugin
{
    public const string ID = 'commerz.shipping';

    public function manifest(): PluginManifest
    {
        return new PluginManifest(self::ID, '0.1.0');
    }

    public function register(PluginRegistrar $registrar): void
    {
        $registrar->declareKind(new ContributionKind(DeliveryRate::KIND, DeliveryRate::class, self::ID));
        $registrar->declareKind(new ContributionKind(Carrier::KIND, Carrier::class, self::ID));
        $registrar->contribute(DeliveryRate::KIND, FlatRate::class);
        $registrar->contribute(DeliveryRate::KIND, WeightTableRate::class);
        $registrar->contribute(Carrier::KIND, ManualCarrier::class);
        $registrar->quoteStage(DeliveryStage::class);
    }
}
