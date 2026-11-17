<?php

declare(strict_types=1);

namespace Commerz\Inventory\Plugins;

use Commerz\Inventory\Allocation\Contracts\AllocationStrategy;
use Commerz\Inventory\Allocation\Internal\PriorityAllocation;
use Commerz\Inventory\Allocation\Internal\SingleLocationAllocation;
use Commerz\Inventory\Configuration\InventoryOptions;
use Commerz\Inventory\Diagnostics\InventoryDoctorCheck;
use Commerz\Inventory\Errors\InventoryProblemDefinitions;
use Commerz\Inventory\InventoryServiceProvider;
use Commerz\Inventory\Panels\StockLevelsPanel;
use Commerz\Inventory\Panels\StockLocationsPanel;
use Commerz\Inventory\Schema\InventorySchemaSets;
use Commerz\Plugins\CorePlugin;
use Override;
use Packman\Attributes\Api;
use Packman\Extensions\Concerns\Composition\ProvidesPanels;
use Packman\Extensions\Concerns\Configuration\Configurable;
use Packman\Extensions\Concerns\Persistence\OwnsSchema;
use Packman\Extensions\Concerns\Runtime\ProvidesDiagnostics;
use Packman\Extensions\Contracts\Composition\WithPanels;
use Packman\Extensions\Contracts\Configuration\WithConfiguration;
use Packman\Extensions\Contracts\Persistence\WithSchema;
use Packman\Extensions\Contracts\Runtime\WithDiagnostics;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Packman\Plugins\Data\Contributions\ContributionKind;
use Packman\Plugins\Data\Plugin\PluginManifest;
use Packman\Plugins\Laravel\PluginRegistrar;

/**
 * The inventory's code plugin: declares `allocation_strategy`, contributes built-in strategies,
 * and exposes inventory configuration, schema, diagnostics, and panels.
 */
#[Api(since: '0.1')]
final class InventoryPlugin implements ExtensionPlugin, WithConfiguration, WithDiagnostics, WithPanels, WithSchema
{
    use Configurable;
    use OwnsSchema;
    use ProvidesDiagnostics;
    use ProvidesPanels;

    public const string ID = 'commerz.inventory';

    #[Override]
    public function manifest(): PluginManifest
    {
        return new PluginManifest(
            id: self::ID,
            version: '0.1.0',
            requires: [CorePlugin::ID],
            resourceRoot: dirname(__DIR__, 2),
            databases: ['pgsql'],
        );
    }

    #[Override]
    public function register(PluginRegistrar $registrar): void
    {
        $registrar->declareKind(new ContributionKind(AllocationStrategy::KIND, AllocationStrategy::class, self::ID));

        foreach ([PriorityAllocation::class, SingleLocationAllocation::class] as $strategy) {
            $registrar->contribute(AllocationStrategy::KIND, $strategy);
        }
    }

    #[Override]
    public function configRoot(): string
    {
        return 'commerz-inventory';
    }

    #[Override]
    public function defaultConfigPath(): string
    {
        return dirname(__DIR__, 2).'/config/commerz-inventory.php';
    }

    /** @return class-string<InventoryOptions> */
    #[Override]
    public function optionsClass(): string
    {
        return InventoryOptions::class;
    }

    #[Override]
    public function tablePrefix(): string
    {
        return 'cmz_';
    }

    /** @return list<string> */
    #[Override]
    public function tableNouns(): array
    {
        return InventoryServiceProvider::NOUNS;
    }

    #[Override]
    public function migrationPath(): string
    {
        return dirname(__DIR__, 2).'/database/migrations';
    }

    /** @return list<class-string> */
    #[Override]
    public function schemaSetProviders(): array
    {
        return [InventorySchemaSets::class];
    }

    /** @return list<class-string> */
    #[Override]
    public function healthChecks(): array
    {
        return [InventoryDoctorCheck::class];
    }

    /** @return list<class-string> */
    #[Override]
    public function problemProviders(): array
    {
        return [InventoryProblemDefinitions::class];
    }

    /** @return list<class-string> */
    #[Override]
    public function panelProviders(): array
    {
        return [StockLocationsPanel::class, StockLevelsPanel::class];
    }
}
