<?php

declare(strict_types=1);

namespace Commerz\Sales\Fulfillments\Contracts;

use Commerz\Errors\IdempotencyConflict;
use Commerz\Pagination\Page;
use Commerz\Sales\Common\Exceptions\LineNotFound;
use Commerz\Sales\Common\Exceptions\QuantityExceedsOutstanding;
use Commerz\Sales\Fulfillments\Data\CreateFulfillmentOrder;
use Commerz\Sales\Fulfillments\Data\FulfillmentOrder;
use Commerz\Sales\Fulfillments\Data\FulfillmentOrderId;
use Commerz\Sales\Fulfillments\Data\ListFulfillmentOrders;
use Commerz\Sales\Fulfillments\Data\ReassignFulfillmentOrder;
use Commerz\Sales\Fulfillments\Data\TransitionFulfillmentOrder;
use Commerz\Sales\Fulfillments\Exceptions\FulfillmentOrderNotFound;
use Commerz\Sales\Fulfillments\Exceptions\InvalidFulfillmentOrderState;
use Commerz\Sales\Fulfillments\Exceptions\OrderNotFulfillable;
use Commerz\Sales\Orders\Data\OrderId;
use Commerz\Sales\Orders\Exceptions\OrderNotFound;
use Packman\Attributes\Api;

/**
 * Warehouse assignment work orders ({@see FulfillmentOrder}) between placed orders and physical fulfillments.
 */
#[Api(since: '0.1')]
interface FulfillmentOrders
{
    /**
     * Creates a warehouse assignment for lines of a placed order. A repeat with the same idempotency key returns
     * the same assignment.
     *
     * @throws OrderNotFound
     * @throws OrderNotFulfillable the order is cancelled or completed
     * @throws LineNotFound
     * @throws QuantityExceedsOutstanding fewer unassigned units remain on a line
     * @throws IdempotencyConflict
     */
    public function create(CreateFulfillmentOrder $command): FulfillmentOrder;

    /**
     * Reassigns an open or held fulfillment order to another warehouse location.
     *
     * @throws FulfillmentOrderNotFound
     * @throws InvalidFulfillmentOrderState
     */
    public function reassign(ReassignFulfillmentOrder $command): FulfillmentOrder;

    /**
     * Transitions a fulfillment order to a new lifecycle status, optionally linking a physical fulfillment.
     *
     * @throws FulfillmentOrderNotFound
     * @throws InvalidFulfillmentOrderState
     */
    public function transition(TransitionFulfillmentOrder $command): FulfillmentOrder;

    /** @throws FulfillmentOrderNotFound */
    public function find(FulfillmentOrderId $id): FulfillmentOrder;

    /** @return Page<FulfillmentOrder> */
    public function list(ListFulfillmentOrders $query): Page;

    /** @return list<FulfillmentOrder> */
    public function forOrder(OrderId $order): array;
}
