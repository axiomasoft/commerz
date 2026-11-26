<?php

declare(strict_types=1);

namespace Commerz\B2b;

use Commerz\B2b\Laravel\Registrars\B2bRegistrar;
use Commerz\B2b\Plugins\B2bPlugin;
use Commerz\Laravel\CommerzPluginServiceProvider;
use Packman\Attributes\Api;
use Packman\Foundation\Contracts\PackageRegistrar;

#[Api(since: '0.1')]
final class B2bServiceProvider extends CommerzPluginServiceProvider
{
    protected const string PLUGIN = B2bPlugin::class;

    public const array NOUNS = ['b2b'];

    /** @var list<class-string<PackageRegistrar>> */
    protected const array REGISTRARS = [
        B2bRegistrar::class,
    ];
}
