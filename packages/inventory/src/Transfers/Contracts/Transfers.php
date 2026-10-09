<?php

declare(strict_types=1);

namespace Commerz\Inventory\Transfers\Contracts;

use Commerz\Attributes\Api;
use Commerz\Errors\IdempotencyConflict;
use Commerz\Errors\InvalidCommand;
use Commerz\Inventory\Items\Exceptions\StockItemArchived;
use Commerz\Inventory\Items\Exceptions\StockItemNotFound;
use Commerz\Inventory\Items\Exceptions\UntrackedStockItem;
use Commerz\Inventory\Locations\Exceptions\LocationArchived;
use Commerz\Inventory\Locations\Exceptions\LocationNotFound;
use Commerz\Inventory\Transfers\Data\CancelTransfer;
use Commerz\Inventory\Transfers\Data\CreateTransfer;
use Commerz\Inventory\Transfers\Data\DispatchTransfer;
use Commerz\Inventory\Transfers\Data\ListTransfers;
use Commerz\Inventory\Transfers\Data\ReceiveTransfer;
use Commerz\Inventory\Transfers\Data\TransferId;
use Commerz\Inventory\Transfers\Data\TransferView;
use Commerz\Inventory\Transfers\Exceptions\InvalidTransferState;
use Commerz\Inventory\Transfers\Exceptions\TransferNotFound;
use Commerz\Pagination\Page;
use Commerz\Ports\Stock\Exceptions\InsufficientStock;

/**
 * Moves stock between two locations: `draft` (the units are reserved at the source) → `in_transit` (they left the
 * source) → `completed` (received at the destination, less what was written off). The goods in transit sit on no
 * level, only on the transfer's lines. One shipment per transfer; every command takes the transfer row first, so
 * concurrent commands on a transfer run one after the other.
 */
#[Api(since: '0.1')]
interface Transfers
{
    /**
     * @throws InvalidCommand
     * @throws LocationNotFound|LocationArchived
     * @throws StockItemNotFound|StockItemArchived|UntrackedStockItem
     * @throws InsufficientStock a line asks for more than the source has free (`stock_item:{id}` as the sellable)
     * @throws IdempotencyConflict
     */
    public function create(CreateTransfer $command): TransferView;

    /**
     * @throws TransferNotFound
     * @throws InvalidTransferState the transfer is not a draft
     * @throws InsufficientStock the source no longer has the reserved units on hand (a count lowered it)
     */
    public function dispatch(DispatchTransfer $command): TransferView;

    /**
     * @throws TransferNotFound
     * @throws InvalidTransferState the transfer is not in transit
     * @throws InvalidCommand a receipt names a foreign item or exceeds what is in transit
     * @throws StockItemArchived|UntrackedStockItem|LocationArchived
     * @throws IdempotencyConflict
     */
    public function receive(ReceiveTransfer $command): TransferView;

    /**
     * A cancelled transfer stays cancelled; a repeat returns it.
     *
     * @throws TransferNotFound
     * @throws InvalidTransferState the transfer is in transit or completed
     */
    public function cancel(CancelTransfer $command): TransferView;

    /** @throws TransferNotFound */
    public function find(TransferId $id): TransferView;

    /** @return Page<TransferView> */
    public function list(ListTransfers $query): Page;
}
