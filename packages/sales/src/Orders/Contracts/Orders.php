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
use Commerz\Sales\Orders\Data\DraftOrder;
use Commerz\Sales\Orders\Data\ListOrders;
use Commerz\Sales\Orders\Data\OrderEventView;
use Commerz\Sales\Orders\Data\OrderId;
use Commerz\Sales\Orders\Data\OrderView;
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
}
