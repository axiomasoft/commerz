<?php

declare(strict_types=1);

namespace Commerz\Inventory;

use Commerz\Inventory\Items\Contracts\StockItems;
use Commerz\Inventory\Locations\Contracts\Locations;
use Commerz\Inventory\Movements\Contracts\Movements;
use Commerz\Inventory\Reservations\Contracts\Reservations;
use Commerz\Inventory\Sources\Contracts\StockSources;
use Commerz\Inventory\Stock\Contracts\StockLevels;
use Commerz\Inventory\Transfers\Contracts\Transfers;
use Commerz\Ports\Stock\Contracts\StockAvailability;
use Packman\Attributes\Api;

/** Root of the inventory API: accessors of the module contracts. */
#[Api(since: '0.1')]
final readonly class Inventory
{
    public function __construct(
        private Locations $locations,
        private StockItems $items,
        private StockSources $sources,
        private StockLevels $stock,
        private Movements $movements,
        private Reservations $reservations,
        private StockAvailability $availability,
        private Transfers $transfers,
    ) {}

    public function locations(): Locations
    {
        return $this->locations;
    }

    public function items(): StockItems
    {
        return $this->items;
    }

    public function sources(): StockSources
    {
        return $this->sources;
    }

    public function stock(): StockLevels
    {
        return $this->stock;
    }

    public function movements(): Movements
    {
        return $this->movements;
    }

    public function reservations(): Reservations
    {
        return $this->reservations;
    }

    public function availability(): StockAvailability
    {
        return $this->availability;
    }

    public function transfers(): Transfers
    {
        return $this->transfers;
    }
}
