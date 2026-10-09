<?php

declare(strict_types=1);

namespace Commerz\Inventory;

use Commerz\Attributes\Api;
use Commerz\Inventory\Items\Contracts\StockItems;
use Commerz\Inventory\Locations\Contracts\Locations;
use Commerz\Inventory\Movements\Contracts\Movements;
use Commerz\Inventory\Sources\Contracts\StockSources;
use Commerz\Inventory\Stock\Contracts\StockLevels;

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
}
