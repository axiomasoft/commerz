<?php

declare(strict_types=1);

namespace Commerz\Sales\OrderChanges\Contracts;

use Commerz\Errors\InvalidCommand;
use Commerz\Identity\ActorRef;
use Commerz\Identity\IdempotencyKey;
use Commerz\Ports\Sellables\Exceptions\SellableUnavailable;
use Commerz\Ports\Stock\Exceptions\InsufficientStock;
use Commerz\Quotes\Exceptions\QuoteFailed;
use Commerz\Sales\Carts\Exceptions\SellableNotPurchasable;
use Commerz\Sales\Carts\Exceptions\ShippingAddressRequired;
use Commerz\Sales\Common\Exceptions\LineNotFound;
use Commerz\Sales\Common\Exceptions\OperationInsideTransaction;
use Commerz\Sales\OrderChanges\Data\AddOrderChangeAction;
use Commerz\Sales\OrderChanges\Data\CancelOrderChange;
use Commerz\Sales\OrderChanges\Data\CompensationPreference;
use Commerz\Sales\OrderChanges\Data\CreateOrderChange;
use Commerz\Sales\OrderChanges\Data\OrderChangeConfirmation;
use Commerz\Sales\OrderChanges\Data\OrderChangeId;
use Commerz\Sales\OrderChanges\Data\OrderChangePreview;
use Commerz\Sales\OrderChanges\Data\OrderChangeView;
use Commerz\Sales\OrderChanges\Exceptions\OrderChangeLineLocked;
use Commerz\Sales\OrderChanges\Exceptions\OrderChangeNotFound;
use Commerz\Sales\OrderChanges\Exceptions\OrderChangeNotOpen;
use Commerz\Sales\OrderChanges\Exceptions\OrderChangeVersionConflict;
use Commerz\Sales\OrderChanges\Exceptions\OrderNotEditable;
use Commerz\Sales\Orders\Data\OrderId;
use Commerz\Sales\Orders\Exceptions\OrderNotFound;
use Packman\Attributes\Api;

/**
 * Two-phase post-placement order editing (`OrderChange`) with dry-run preview, optimistic concurrency control
 * (`INV-OMS-001`), stock reservation delta reconciliation, and financial compensating workflow.
 */
#[Api(since: '0.1')]
interface OrderChanges
{
    /**
     * Opens a new draft order change anchored to the order's current version.
     *
     * @throws OrderNotFound
     * @throws OrderNotEditable
     */
    public function create(CreateOrderChange $command): OrderChangeView;

    /**
     * Appends an action (`AddLine`, `ChangeLineQuantity`, `RemoveLine`, `OverrideUnitPrice`, `ChangeShippingMethod`,
     * `ReplaceAdjustments`) to an open draft order change without mutating the order.
     *
     * @throws OrderChangeNotFound
     * @throws OrderChangeNotOpen
     * @throws InvalidCommand
     */
    public function addAction(AddOrderChangeAction $command): OrderChangeView;

    /**
     * Pure dry-run calculation without mutating the order or database: returns `excerptBefore`, `excerptAfter`,
     * tax delta, and signed `amountDelta`.
     *
     * @throws OrderChangeNotFound
     * @throws OrderNotFound
     * @throws LineNotFound
     * @throws OrderChangeLineLocked
     * @throws SellableUnavailable
     * @throws SellableNotPurchasable
     * @throws ShippingAddressRequired
     * @throws QuoteFailed
     * @throws InvalidCommand
     */
    public function preview(OrderChangeId $change): OrderChangePreview;

    /**
     * Atomically applies all queued actions to the order guarded by OCC (`$expectedOrderVersion`, `INV-OMS-001`),
     * reconciles stock reservations, bumps `orders.version`, and runs the financial compensating workflow:
     * - `amountDelta > 0`: incremental authorization or new delta charge (or deferred)
     * - `amountDelta < 0`: partial refund or customer wallet credit via `StoredValueTender` (or deferred)
     *
     * @throws OrderChangeNotFound
     * @throws OrderChangeNotOpen
     * @throws OrderNotFound
     * @throws OrderNotEditable
     * @throws OrderChangeVersionConflict 409 `ORDERS_ORDER_CHANGE_VERSION_CONFLICT` (`INV-OMS-001`)
     * @throws OperationInsideTransaction when gateway compensation is needed inside an active DB transaction
     * @throws LineNotFound
     * @throws OrderChangeLineLocked
     * @throws SellableUnavailable
     * @throws SellableNotPurchasable
     * @throws ShippingAddressRequired
     * @throws InsufficientStock
     * @throws QuoteFailed
     * @throws InvalidCommand
     */
    public function confirm(
        OrderChangeId $change,
        int $expectedOrderVersion,
        ?CompensationPreference $compensation = null,
        ?IdempotencyKey $idempotencyKey = null,
        ?ActorRef $actor = null,
    ): OrderChangeConfirmation;

    /**
     * Cancels a draft order change without modifying the order.
     *
     * @throws OrderChangeNotFound
     * @throws OrderChangeNotOpen
     */
    public function cancel(CancelOrderChange $command): OrderChangeView;

    /** @throws OrderChangeNotFound */
    public function find(OrderChangeId $id): OrderChangeView;

    /** @return list<OrderChangeView> newest first */
    public function listForOrder(OrderId $order): array;
}
