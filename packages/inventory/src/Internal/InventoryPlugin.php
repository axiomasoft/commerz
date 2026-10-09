<?php

declare(strict_types=1);

namespace Commerz\Inventory\Internal;

use Commerz\Inventory\Allocation\Contracts\AllocationStrategy;
use Commerz\Inventory\Allocation\Internal\PriorityAllocation;
use Commerz\Inventory\Allocation\Internal\SingleLocationAllocation;
use Commerz\Plugins\Contracts\CommerzPlugin;
use Commerz\Plugins\ContributionKind;
use Commerz\Plugins\PluginManifest;
use Commerz\Plugins\PluginRegistrar;

/** The inventory's own plugin: declares the `allocation_strategy` kind and contributes the built-in strategies. */
final class InventoryPlugin implements CommerzPlugin
{
    public const string ID = 'commerz.inventory';

    public function manifest(): PluginManifest
    {
        return new PluginManifest(self::ID, '0.1.0');
    }

    public function register(PluginRegistrar $registrar): void
    {
        $registrar->declareKind(new ContributionKind(AllocationStrategy::KIND, AllocationStrategy::class, self::ID));

        foreach ([PriorityAllocation::class, SingleLocationAllocation::class] as $strategy) {
            $registrar->contribute(AllocationStrategy::KIND, $strategy);
        }
    }
}
