<?php

declare(strict_types=1);

namespace Commerz\Plugins\Contracts;

use Commerz\Attributes\Api;
use Commerz\Plugins\PluginDescription;

/** Read-only view of the compiled registry for diagnostics (`commerz:plugins`). */
#[Api(since: '0.1')]
interface PluginInspector
{
    /** @return list<PluginDescription> plugins in load order */
    public function describe(): array;
}
