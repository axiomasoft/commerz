<?php

declare(strict_types=1);

namespace Commerz\Facades;

use Commerz\Ports\Sellables\Contracts\Sellables;
use Commerz\Quotes\Contracts\QuotePipeline;
use Commerz\Stores\Contracts\Channels;
use Commerz\Stores\Contracts\Stores;
use Illuminate\Support\Facades\Facade;
use Packman\Attributes\Api;
use Packman\Plugins\Contracts\Registry\PluginInspector;

/**
 * @method static Stores stores()
 * @method static Channels channels()
 * @method static PluginInspector plugins()
 * @method static QuotePipeline quotes()
 * @method static Sellables sellables()
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
