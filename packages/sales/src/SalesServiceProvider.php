<?php

declare(strict_types=1);

namespace Commerz\Sales;

use Commerz\Laravel\CommerzPluginServiceProvider;
use Commerz\Sales\Laravel\Registrars\SalesRegistrar;
use Commerz\Sales\Plugins\SalesPlugin;
use Packman\Attributes\Api;
use Packman\Foundation\Contracts\PackageRegistrar;

#[Api(since: '0.1')]
final class SalesServiceProvider extends CommerzPluginServiceProvider
{
    protected const string PLUGIN = SalesPlugin::class;

    public const array NOUNS = ['cart', 'checkout', 'order', 'payment', 'fulfillment', 'return', 'refund'];

    /** @var list<class-string<PackageRegistrar>> */
    protected const array REGISTRARS = [
        SalesRegistrar::class,
    ];
}
