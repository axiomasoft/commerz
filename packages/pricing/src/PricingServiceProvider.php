<?php

declare(strict_types=1);

namespace Commerz\Pricing;

use Commerz\Laravel\CommerzPluginServiceProvider;
use Commerz\Pricing\Laravel\Registrars\PricingRegistrar;
use Commerz\Pricing\Plugins\PricingPlugin;
use Packman\Attributes\Api;
use Packman\Foundation\Contracts\PackageRegistrar;

#[Api(since: '0.1')]
final class PricingServiceProvider extends CommerzPluginServiceProvider
{
    protected const string PLUGIN = PricingPlugin::class;

    public const array NOUNS = ['price', 'staged_price', 'tax', 'market'];

    /** @var list<class-string<PackageRegistrar>> */
    protected const array REGISTRARS = [
        PricingRegistrar::class,
    ];
}
