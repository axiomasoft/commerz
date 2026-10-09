<?php

declare(strict_types=1);

namespace Commerz\Pricing\Internal;

use Commerz\Plugins\Contracts\CommerzPlugin;
use Commerz\Plugins\ContributionKind;
use Commerz\Plugins\PluginManifest;
use Commerz\Plugins\PluginRegistrar;
use Commerz\Pricing\Calculation\Contracts\TaxCalculator;

/** The pricing's own plugin: declares the `tax_calculator` kind; the built-in `rates` calculator and the tax stage join it later. */
final class PricingPlugin implements CommerzPlugin
{
    public const string ID = 'commerz.pricing';

    public function manifest(): PluginManifest
    {
        return new PluginManifest(self::ID, '0.1.0');
    }

    public function register(PluginRegistrar $registrar): void
    {
        $registrar->declareKind(new ContributionKind(TaxCalculator::KIND, TaxCalculator::class, self::ID));
    }
}
