<?php

declare(strict_types=1);

namespace Commerz\Inventory\Facades;

use Commerz\Attributes\Api;
use Commerz\Inventory\Items\Contracts\StockItems;
use Commerz\Inventory\Locations\Contracts\Locations;
use Commerz\Inventory\Movements\Contracts\Movements;
use Commerz\Inventory\Sources\Contracts\StockSources;
use Commerz\Inventory\Stock\Contracts\StockLevels;
use Illuminate\Support\Facades\Facade;

/**
 * @method static Locations locations()
 * @method static StockItems items()
 * @method static StockSources sources()
 * @method static StockLevels stock()
 * @method static Movements movements()
 *
 * @see \Commerz\Inventory\Inventory
 */
#[Api(since: '0.1')]
final class Inventory extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Commerz\Inventory\Inventory::class;
    }
}
