<?php

declare(strict_types=1);

namespace Commerz\Facades;

use Commerz\Attributes\Api;
use Commerz\Plugins\Contracts\PluginInspector;
use Commerz\Stores\Contracts\Channels;
use Commerz\Stores\Contracts\Stores;
use Illuminate\Support\Facades\Facade;

/**
 * @method static Stores stores()
 * @method static Channels channels()
 * @method static PluginInspector plugins()
 *
 * @see \Commerz\Commerz
 */
#[Api(since: '0.1')]
final class Commerz extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Commerz\Commerz::class;
    }
}
