<?php

declare(strict_types=1);

namespace Commerz\Shipping;

use Commerz\Laravel\CommerzPluginServiceProvider;
use Commerz\Shipping\Laravel\Registrars\ShippingRegistrar;
use Commerz\Shipping\Plugins\ShippingPlugin;
use Packman\Attributes\Api;
use Packman\Foundation\Contracts\PackageRegistrar;

#[Api(since: '0.1')]
final class ShippingServiceProvider extends CommerzPluginServiceProvider
{
    protected const string PLUGIN = ShippingPlugin::class;

    public const array NOUNS = ['shipping'];

    /** @var list<class-string<PackageRegistrar>> */
    protected const array REGISTRARS = [
        ShippingRegistrar::class,
    ];
}
