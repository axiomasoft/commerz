<?php

declare(strict_types=1);

namespace Commerz\Inventory\Items\Contracts;

use Commerz\Attributes\Api;
use Commerz\Inventory\Common\Exceptions\DuplicateCode;
use Commerz\Inventory\Items\Data\ArchiveStockItem;
use Commerz\Inventory\Items\Data\CreateStockItem;
use Commerz\Inventory\Items\Data\LinkSellable;
use Commerz\Inventory\Items\Data\ListLinks;
use Commerz\Inventory\Items\Data\ListStockItems;
use Commerz\Inventory\Items\Data\RestoreStockItem;
use Commerz\Inventory\Items\Data\StockItemId;
use Commerz\Inventory\Items\Data\StockItemView;
use Commerz\Inventory\Items\Data\StockLinkView;
use Commerz\Inventory\Items\Data\UnlinkSellable;
use Commerz\Inventory\Items\Data\UpdateStockItem;
use Commerz\Inventory\Items\Exceptions\StockItemArchived;
use Commerz\Inventory\Items\Exceptions\StockItemNotEmpty;
use Commerz\Inventory\Items\Exceptions\StockItemNotFound;
use Commerz\Inventory\Items\Exceptions\TooManyComponents;
use Commerz\Inventory\Items\Exceptions\UntrackedStockItem;
use Commerz\Pagination\Page;

/** What lies on the shelf, and which sellables draw from it. */
#[Api(since: '0.1')]
interface StockItems
{
    /** @throws DuplicateCode */
    public function create(CreateStockItem $command): StockItemView;

    /** @throws StockItemNotFound|StockItemArchived|UntrackedStockItem */
    public function update(UpdateStockItem $command): StockItemView;

    /** @throws StockItemNotFound|StockItemNotEmpty */
    public function archive(ArchiveStockItem $command): StockItemView;

    /** @throws StockItemNotFound */
    public function restore(RestoreStockItem $command): StockItemView;

    /** @throws StockItemNotFound */
    public function find(StockItemId $id): StockItemView;

    /** @throws StockItemNotFound */
    public function findBySku(string $sku): StockItemView;

    /** @return Page<StockItemView> */
    public function list(ListStockItems $query): Page;

    /** @throws StockItemNotFound|StockItemArchived|TooManyComponents */
    public function link(LinkSellable $command): StockLinkView;

    public function unlink(UnlinkSellable $command): void;

    /** @return Page<StockLinkView> */
    public function links(ListLinks $query): Page;
}
