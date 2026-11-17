<?php

declare(strict_types=1);

namespace Commerz\Plugins;

use Commerz\Plugins\Internal\BuiltInPluginCatalog;
use Illuminate\Container\Container;
use Packman\Attributes\Api;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Packman\Plugins\Laravel\ExtensionRegistrar;

/**
 * Entry point for registering built-in Commerz extension plugins with both Packman and the Commerz catalog.
 */
#[Api(since: '0.1')]
final class CommerzPlugins
{
    /** @param class-string<ExtensionPlugin> ...$plugins */
    public static function builtIn(string ...$plugins): void
    {
        $container = Container::getInstance();
        $container->singletonIf(BuiltInPluginCatalog::class, static fn (): BuiltInPluginCatalog => new BuiltInPluginCatalog);
        $catalog = $container->make(BuiltInPluginCatalog::class);

        foreach ($plugins as $plugin) {
            ExtensionRegistrar::builtIn($plugin);
            $catalog->add($plugin);
        }
    }
}
