<?php

declare(strict_types=1);

namespace Commerz\Plugins\Internal;

use Commerz\Plugins\Contracts\CommerzPlugin;
use Commerz\Plugins\PluginManifest;

/** One instantiated plugin during compilation. */
final readonly class PluginNode
{
    public function __construct(
        public PluginEntry $entry,
        public CommerzPlugin $plugin,
        public PluginManifest $manifest,
        public PluginDeclarations $declarations,
    ) {}
}
