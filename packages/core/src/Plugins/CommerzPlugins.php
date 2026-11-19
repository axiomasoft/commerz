<?php

declare(strict_types=1);

namespace Commerz\Plugins;

use Illuminate\Contracts\Container\Container;
use Packman\Attributes\Api;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Packman\Plugins\Laravel\ExtensionRegistrar;

/**
 * Entry point for registering built-in Commerz extension plugins with Packman.
 */
#[Api(since: '0.1')]
final class CommerzPlugins
{
    /** @param class-string<ExtensionPlugin> ...$plugins */
    public static function builtIn(string ...$plugins): void
    {
        ExtensionRegistrar::builtIn(...$plugins);
    }

    /** @param class-string<ExtensionPlugin> ...$plugins */
    public static function builtInFor(Container $container, string ...$plugins): void
    {
        ExtensionRegistrar::builtInFor($container, ...$plugins);
    }
}
