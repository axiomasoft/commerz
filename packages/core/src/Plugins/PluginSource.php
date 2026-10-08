<?php

declare(strict_types=1);

namespace Commerz\Plugins;

use Commerz\Attributes\Api;

/** Where a plugin was registered from. */
#[Api(since: '0.1')]
enum PluginSource: string
{
    /** Registered by a Commerz package through `CommerzPlugins::builtIn()`. */
    case BuiltIn = 'builtin';

    /** Listed in `config('commerz.plugins')` by the host. */
    case Config = 'config';
}
