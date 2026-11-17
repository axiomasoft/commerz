<?php

declare(strict_types=1);

namespace Commerz\Plugins\Contracts;

use Commerz\Plugins\PluginManifest;
use Commerz\Plugins\PluginRegistrar;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * Legacy plugin contract retained in Slice A for unmigrated feature subpackages.
 *
 * @deprecated Implement Packman\Plugins\Contracts\Registry\ExtensionPlugin instead.
 */
#[Spi(kind: SpiKind::Implement, since: '0.1')]
interface CommerzPlugin
{
    public function manifest(): PluginManifest;

    public function register(PluginRegistrar $registrar): void;
}
