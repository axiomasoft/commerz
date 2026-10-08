<?php

declare(strict_types=1);

namespace Commerz\Plugins\Internal;

use Commerz\Plugins\Contracts\CommerzPlugin;
use Commerz\Plugins\PluginSource;

/** A plugin class waiting to be compiled, with the place it came from for error messages. */
final readonly class PluginEntry
{
    /**
     * @param  class-string  $class
     * @param  string  $origin  `commerz.plugins.2` for config, `builtin <class>` for a package
     */
    private function __construct(public string $class, public PluginSource $source, public string $origin) {}

    /** @param  class-string<CommerzPlugin>  $class */
    public static function builtIn(string $class): self
    {
        return new self($class, PluginSource::BuiltIn, 'builtin '.$class);
    }

    /** @param  class-string  $class */
    public static function config(string $class, int $index): self
    {
        return new self($class, PluginSource::Config, "commerz.plugins.{$index}");
    }
}
