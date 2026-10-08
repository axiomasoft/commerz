<?php

declare(strict_types=1);

namespace Commerz\Plugins\Internal;

use Commerz\Configuration\CommerzConfig;
use Commerz\Plugins\Contracts\CommerzPlugin;
use LogicException;

/** Collects plugin classes until the first compilation, then freezes. One instance per application. */
final class PluginCatalog
{
    /** @var array<class-string<CommerzPlugin>, true> */
    private array $builtIn = [];

    private bool $sealed = false;

    /** @param  class-string<CommerzPlugin>  $class */
    public function builtIn(string $class): void
    {
        if ($this->sealed) {
            throw new LogicException("Cannot register built-in plugin {$class}: the plugin graph is already compiled.");
        }

        $this->builtIn[$class] = true;
    }

    public function seal(): self
    {
        $this->sealed = true;

        return $this;
    }

    /** @return list<PluginEntry> built-in plugins in registration order, then the host's configured plugins */
    public function entries(CommerzConfig $config): array
    {
        $entries = [];

        foreach (array_keys($this->builtIn) as $class) {
            $entries[] = PluginEntry::builtIn($class);
        }

        foreach ($config->plugins as $index => $class) {
            $entries[] = PluginEntry::config($class, $index);
        }

        return $entries;
    }
}
