<?php

declare(strict_types=1);

namespace Commerz\Plugins;

use Commerz\Attributes\Api;
use Commerz\Plugins\Contracts\CommerzPlugin;
use Commerz\Plugins\Internal\PluginCatalog;
use Illuminate\Container\Container;

/**
 * Entry point for packages: call from a service provider's `register()` to add the package's built-in plugin.
 * Hosts list their own plugins in `config('commerz.plugins')` instead. Registration must happen before the first
 * use of `Contributions`; afterwards the catalog is frozen and this throws a LogicException.
 */
#[Api(since: '0.1')]
final class CommerzPlugins
{
    /** @param  class-string<CommerzPlugin>  ...$plugins */
    public static function builtIn(string ...$plugins): void
    {
        $container = Container::getInstance();
        $container->singletonIf(PluginCatalog::class);
        $catalog = $container->make(PluginCatalog::class);

        foreach ($plugins as $plugin) {
            $catalog->builtIn($plugin);
        }
    }
}
