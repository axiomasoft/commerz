<?php

declare(strict_types=1);

namespace Commerz;

use Commerz\Attributes\Api;
use Commerz\Errors\InvalidConfiguration;
use Commerz\Internal\CorePlugin;
use Commerz\Plugins\Contracts\Contributions;
use Commerz\Plugins\Contracts\PluginInspector;
use Commerz\Plugins\Internal\PluginEntry;
use Commerz\Plugins\Internal\PluginGraphCompiler;
use Illuminate\Contracts\Container\Container;

/**
 * Compiles a plugin set the way the application does, without booting providers: `commerz.core` first, then the
 * given plugin classes in order. For plugin contract tests and tooling.
 */
#[Api(since: '0.1')]
final class PluginGraph
{
    /**
     * @param  list<class-string>  $plugins
     *
     * @throws InvalidConfiguration when the graph is invalid (duplicate, missing dependency, cycle, bad contribution)
     */
    public static function compile(Container $container, array $plugins): Contributions&PluginInspector
    {
        $entries = [PluginEntry::builtIn(CorePlugin::class)];

        foreach ($plugins as $index => $class) {
            $entries[] = PluginEntry::config($class, $index);
        }

        return new PluginGraphCompiler($container)->compile($entries);
    }
}
