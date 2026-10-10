<?php

declare(strict_types=1);

namespace Commerz\Sales\Orders\Contracts;

use Commerz\Attributes\Api;
use Commerz\Errors\InvalidCommand;
use Commerz\Pagination\Page;
use Commerz\Ports\Sellables\Exceptions\SellableUnavailable;
use Commerz\Sales\Carts\Data\CartView;
use Commerz\Sales\Carts\Exceptions\SellableNotPurchasable;
use Commerz\Sales\Carts\Exceptions\TooManyCartLines;
use Commerz\Sales\Common\Exceptions\LineNotFound;
use Commerz\Sales\Common\Exceptions\QuantityExceedsOutstanding;
use Commerz\Sales\Orders\Data\CancelOrder;
use Commerz\Sales\Orders\Data\CancelOrderLines;
use Commerz\Sales\Orders\Data\DraftOrder;
use Commerz\Sales\Orders\Data\ExpireOrders;
use Commerz\Sales\Orders\Data\ExpireReport;
use Commerz\Sales\Orders\Data\ListOrders;
use Commerz\Sales\Orders\Data\OrderEventView;
use Commerz\Sales\Orders\Data\OrderId;
use Commerz\Sales\Orders\Data\OrderView;
use Commerz\Sales\Orders\Exceptions\OrderNotCancellable;
use Commerz\Sales\Orders\Exceptions\OrderNotFound;
use Commerz\Stores\Data\StoreId;
use Commerz\Stores\Exceptions\ChannelArchived;
use Commerz\Stores\Exceptions\ChannelNotFound;
use Commerz\Stores\Exceptions\CurrencyNotAllowed;

/** Placed orders, and the drafts an operator assembles before placing them. */
#[Api(since: '0.1')]
interface Orders
{
    /**
     * Opens a draft (D31): a cart of kind `draft` with the lines, codes, addresses and delivery method of the command,
     * edited further with `Carts` and placed with `Checkout::place`. A draft has no payment deadline.
     *
     * @throws ChannelNotFound|ChannelArchived|CurrencyNotAllowed
     * @throws SellableUnavailable
     * @throws SellableNotPurchasable
     * @throws TooManyCartLines
     * @throws InvalidCommand sales.invalid_quantity when a line would exceed `max_quantity`
     */
    public function draft(DraftOrder $command): CartView;

    /** @throws OrderNotFound */
    public function find(OrderId $id): OrderView;

    /** @throws OrderNotFound */
    public function findByNumber(StoreId $store, string $number): OrderView;

    /** @return Page<OrderView> */
    public function list(ListOrders $query): Page;

    /**
     * The history of the order, oldest first.
     *
     * @return list<OrderEventView>
     *
     * @throws OrderNotFound
     */
    public function history(OrderId $id): array;

    /**
     * Cancels every unit of the order: releases its stock, gives its promotion usage back as the promotions' policy
     * allows, and records `sales.order_cancelled`. A paid order also records `sales.payment_requires_refund`; nothing is
     * refunded automatically. Cancelling a cancelled order changes nothing.
     *
     * @throws OrderNotFound
     * @throws OrderNotCancellable `completed`, or `fulfilled` when some units are fulfilled (cancel the rest with `cancelLines`)
     */
    public function cancel(CancelOrder $command): OrderView;

    /**
     * Cancels units of some lines of a placed order and releases their stock; the amount due shrinks by their exact
     * share of the line totals, delivery stays. Cancelling every unit left is `cancel`; cancelling the rest of a fulfilled
     * and paid order completes it.
     *
     * @throws OrderNotFound|LineNotFound
     * @throws OrderNotCancellable `completed` or `cancelled`
     * @throws QuantityExceedsOutstanding
     */
    public function cancelLines(CancelOrderLines $command): OrderView;

    /**
     * Cancels, with the reason `expired`, the unpaid orders whose payment deadline has passed (D32). An order with a
     * payment under way, a captured payment or a fulfilled unit stays; drafts have no deadline. Safe to run in parallel.
     */
    public function expireDue(ExpireOrders $command): ExpireReport;
}
