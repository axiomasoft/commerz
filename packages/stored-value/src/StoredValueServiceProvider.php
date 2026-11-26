<?php

declare(strict_types=1);

namespace Commerz\StoredValue;

use Commerz\Laravel\CommerzPluginServiceProvider;
use Commerz\StoredValue\Laravel\Registrars\StoredValueRegistrar;
use Commerz\StoredValue\Plugins\StoredValuePlugin;
use Packman\Attributes\Api;
use Packman\Foundation\Contracts\PackageRegistrar;

#[Api(since: '0.1')]
final class StoredValueServiceProvider extends CommerzPluginServiceProvider
{
    protected const string PLUGIN = StoredValuePlugin::class;

    public const array NOUNS = ['stored_value'];

    /** @var list<class-string<PackageRegistrar>> */
    protected const array REGISTRARS = [
        StoredValueRegistrar::class,
    ];
}
