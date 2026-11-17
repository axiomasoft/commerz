<?php

declare(strict_types=1);

namespace Commerz;

use Commerz\Plugins\CorePlugin;
use Commerz\Plugins\Internal\LegacyPluginBridge;
use Illuminate\Contracts\Container\Container;
use Packman\Attributes\Api;
use Packman\Plugins\Contracts\Contributions\Contributions;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Packman\Plugins\Contracts\Registry\PluginInspector;
use Packman\Plugins\Testing\PluginGraph as PackmanPluginGraph;

/**
 * Legacy Slice-A helper for compiling a plugin set without booting providers.
 *
 * @deprecated Use Packman\Plugins\Testing\PluginGraph::compile() directly once all plugins implement ExtensionPlugin.
 */
#[Api(since: '0.1')]
final class PluginGraph
{
    /** @param list<class-string> $plugins */
    public static function compile(Container $container, array $plugins): Contributions&PluginInspector
    {
        $allExtensions = array_all($plugins, static fn (string $class): bool => is_subclass_of($class, ExtensionPlugin::class));

        if ($allExtensions) {
            return PackmanPluginGraph::compile(
                container: $container,
                plugins: $plugins,
                corePluginId: CorePlugin::ID,
                builtIn: [CorePlugin::class],
            );
        }

        return LegacyPluginBridge::compile($container, [CorePlugin::class], $plugins);
    }
}
