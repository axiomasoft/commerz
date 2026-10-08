<?php

declare(strict_types=1);

namespace Commerz\Plugins\Contracts;

use Commerz\Attributes\Spi;
use Commerz\Attributes\SpiKind;
use Commerz\Plugins\PluginManifest;
use Commerz\Plugins\PluginRegistrar;

/**
 * A unit of behavior: pure declarations, no IO, no database, no container lookups. The compiler creates it with
 * `new $class()` (no constructor arguments) once per process and calls `manifest()` and `register()` exactly once.
 * Packages register their built-in contributions through the same contract (`CommerzPlugins::builtIn()`).
 */
#[Spi(kind: SpiKind::Implement, since: '0.1')]
interface CommerzPlugin
{
    public function manifest(): PluginManifest;

    public function register(PluginRegistrar $registrar): void;
}
