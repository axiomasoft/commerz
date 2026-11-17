<?php

declare(strict_types=1);

namespace Commerz\Plugins;

use Commerz\Plugins\Contracts\CommerzPlugin;
use Commerz\Plugins\Internal\LegacyPluginBridge;
use Illuminate\Container\Container;
use Packman\Attributes\Api;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Packman\Plugins\Laravel\ExtensionRegistrar;

/**
 * Entry point for registering built-in Commerz plugins. Routes ExtensionPlugin subclasses directly
 * to Packman and buffers legacy CommerzPlugin classes during Slice A transition.
 *
 * @deprecated Call Packman\Plugins\Laravel\ExtensionRegistrar::builtIn() for migrated ExtensionPlugin classes.
 */
#[Api(since: '0.1')]
final class CommerzPlugins
{
    /** @param class-string<CommerzPlugin|ExtensionPlugin> ...$plugins */
    public static function builtIn(string ...$plugins): void
    {
        $container = Container::getInstance();
        $container->singletonIf(LegacyPluginBridge::class, static fn (): LegacyPluginBridge => new LegacyPluginBridge);
        $bridge = $container->make(LegacyPluginBridge::class);

        foreach ($plugins as $plugin) {
            if (is_subclass_of($plugin, ExtensionPlugin::class)) {
                ExtensionRegistrar::builtIn($plugin);
            }

            $bridge->builtIn($plugin);
        }
    }
}
