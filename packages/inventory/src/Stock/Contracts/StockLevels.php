<?php

declare(strict_types=1);

namespace Commerz\Inventory\Stock\Contracts;

use Commerz\Errors\IdempotencyConflict;
use Commerz\Inventory\Items\Data\StockItemId;
use Commerz\Inventory\Items\Exceptions\StockItemArchived;
use Commerz\Inventory\Items\Exceptions\StockItemNotFound;
use Commerz\Inventory\Items\Exceptions\UntrackedStockItem;
use Commerz\Inventory\Locations\Data\LocationId;
use Commerz\Inventory\Locations\Exceptions\LocationArchived;
use Commerz\Inventory\Locations\Exceptions\LocationNotFound;
use Commerz\Inventory\Stock\Data\AdjustStock;
use Commerz\Inventory\Stock\Data\CountStock;
use Commerz\Inventory\Stock\Data\ListStockLevels;
use Commerz\Inventory\Stock\Data\ReceiveStock;
use Commerz\Inventory\Stock\Data\SetSafetyStock;
use Commerz\Inventory\Stock\Data\StockLevelView;
use Commerz\Inventory\Stock\Exceptions\NegativeStock;
use Commerz\Inventory\Stock\Exceptions\StaleStockCount;
use Commerz\Pagination\Page;
use Packman\Attributes\Api;

/**
 * Physical stock of an item at a location. Every on-hand change writes one journal movement; a change that leaves the
 * level short of its reservations and safety stock succeeds and reports `StockShortfall`. Archived items and
 * locations accept only changes that lower on-hand, so they can be emptied. Mutations join the caller's transaction.
 */
#[Api(since: '0.1')]
interface StockLevels
{
    /** @throws StockItemNotFound|LocationNotFound|UntrackedStockItem|StockItemArchived|LocationArchived|IdempotencyConflict */
    public function receive(ReceiveStock $command): StockLevelView;

    /** @throws StockItemNotFound|LocationNotFound|UntrackedStockItem|StockItemArchived|LocationArchived|NegativeStock|IdempotencyConflict */
    public function adjust(AdjustStock $command): StockLevelView;

    /** @throws StockItemNotFound|LocationNotFound|UntrackedStockItem|StockItemArchived|LocationArchived|StaleStockCount|IdempotencyConflict */
    public function count(CountStock $command): StockLevelView;

    /** @throws StockItemNotFound|LocationNotFound|UntrackedStockItem|StockItemArchived|LocationArchived */
    public function setSafetyStock(SetSafetyStock $command): StockLevelView;

    /** @throws StockItemNotFound|LocationNotFound */
    public function level(StockItemId $item, LocationId $location): StockLevelView;

    /** @return Page<StockLevelView> */
    public function list(ListStockLevels $query): Page;
}
