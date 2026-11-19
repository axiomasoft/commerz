<?php

declare(strict_types=1);

namespace Commerz\Inventory;

use Commerz\Inventory\Laravel\Registrars\InventoryCoreRegistrar;
use Commerz\Inventory\Laravel\Registrars\InventoryPortsRegistrar;
use Commerz\Inventory\Plugins\InventoryPlugin;
use Commerz\Laravel\CommerzPluginServiceProvider;
use Packman\Attributes\Api;
use Packman\Foundation\Contracts\PackageRegistrar;

#[Api(since: '0.1')]
final class InventoryServiceProvider extends CommerzPluginServiceProvider
{
    protected const string PLUGIN = InventoryPlugin::class;

    public const array NOUNS = ['stock'];

    /** @var list<class-string<PackageRegistrar>> */
    protected const array REGISTRARS = [
        InventoryCoreRegistrar::class,
        InventoryPortsRegistrar::class,
    ];
}
