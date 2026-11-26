<?php

declare(strict_types=1);

namespace Commerz\Plugins;

use Commerz\Plugins\Contracts\DescribesConfigSchema;
use Commerz\Plugins\Data\ConfigFieldSchema;
use Override;
use Packman\Attributes\Api;
use Packman\Extensions\Data\ExtensionFacetProfile;
use Packman\Plugins\Contracts\Registry\PluginInspector as PackmanPluginInspector;
use Packman\Plugins\Data\Contributions\SlotContribution;
use Packman\Plugins\Data\Contributions\SlotDefinition;
use Packman\Plugins\Data\Plugin\PluginDescription;

/**
 * Commerz view of the compiled plugin registry, extending Packman's inspector with
 * typed configuration schema discovery for plugins implementing {@see DescribesConfigSchema}.
 */
#[Api(since: '0.1')]
final readonly class PluginInspector implements PackmanPluginInspector
{
    public function __construct(
        private PackmanPluginInspector $inner,
    ) {}

    /** @return list<PluginDescription> */
    #[Override]
    public function describe(): array
    {
        return $this->inner->describe();
    }

    /** @return array<class-string, SlotDefinition> */
    #[Override]
    public function slots(): array
    {
        return $this->inner->slots();
    }

    /** @return array<class-string, list<SlotContribution>> */
    #[Override]
    public function slotContributions(): array
    {
        return $this->inner->slotContributions();
    }

    /** @return list<string> */
    #[Override]
    public function disabledIds(): array
    {
        return $this->inner->disabledIds();
    }

    #[Override]
    public function facetsOf(string $pluginId): ?ExtensionFacetProfile
    {
        return $this->inner->facetsOf($pluginId);
    }

    /**
     * Returns declared configuration field schemas keyed by plugin id for every active plugin
     * that implements {@see DescribesConfigSchema}.
     *
     * @return array<string, list<ConfigFieldSchema>>
     */
    public function describeSchemas(): array
    {
        $schemas = [];

        foreach ($this->inner->describe() as $plugin) {
            if (! is_subclass_of($plugin->class, DescribesConfigSchema::class)) {
                continue;
            }

            /** @var DescribesConfigSchema $instance */
            $instance = new ($plugin->class)();
            $schemas[$plugin->id] = $instance->configSchema();
        }

        return $schemas;
    }
}
