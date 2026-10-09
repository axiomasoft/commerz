<?php

declare(strict_types=1);

namespace Commerz\Inventory\Facades;

use Commerz\Attributes\Api;
use Commerz\Inventory\Items\Contracts\StockItems;
use Commerz\Inventory\Locations\Contracts\Locations;
use Commerz\Inventory\Movements\Contracts\Movements;
use Commerz\Inventory\Reservations\Contracts\Reservations;
use Commerz\Inventory\Sources\Contracts\StockSources;
use Commerz\Inventory\Stock\Contracts\StockLevels;
use Commerz\Ports\Stock\Contracts\StockAvailability;
use Illuminate\Support\Facades\Facade;

/**
 * @method static Locations locations()
 * @method static StockItems items()
 * @method static StockSources sources()
 * @method static StockLevels stock()
 * @method static Movements movements()
 * @method static Reservations reservations()
 * @method static StockAvailability availability()
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
