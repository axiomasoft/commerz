<?php

declare(strict_types=1);

namespace Commerz\Sales\Fulfillments\Contracts;

use Commerz\Errors\IdempotencyConflict;
use Commerz\Pagination\Page;
use Commerz\Ports\Stock\Exceptions\ReservationNotFound;
use Commerz\Sales\Common\Exceptions\LineNotFound;
use Commerz\Sales\Common\Exceptions\OperationInsideTransaction;
use Commerz\Sales\Common\Exceptions\QuantityExceedsOutstanding;
use Commerz\Sales\Fulfillments\Data\BookFulfillment;
use Commerz\Sales\Fulfillments\Data\CreateFulfillment;
use Commerz\Sales\Fulfillments\Data\FulfillmentId;
use Commerz\Sales\Fulfillments\Data\FulfillmentView;
use Commerz\Sales\Fulfillments\Data\ListFulfillments;
use Commerz\Sales\Fulfillments\Data\MarkFulfillmentDelivered;
use Commerz\Sales\Fulfillments\Data\ShipFulfillment;
use Commerz\Sales\Fulfillments\Exceptions\FulfillmentAlreadyShipped;
use Commerz\Sales\Fulfillments\Exceptions\FulfillmentNotBookable;
use Commerz\Sales\Fulfillments\Exceptions\FulfillmentNotFound;
use Commerz\Sales\Fulfillments\Exceptions\FulfillmentNotShipped;
use Commerz\Sales\Fulfillments\Exceptions\OrderNotFulfillable;
use Commerz\Sales\Orders\Exceptions\OrderNotFound;
use Packman\Attributes\Api;
use Packman\Plugins\Exceptions\Registry\UnsupportedContribution;

/**
 * Fulfillments of orders (ORD-03, D14): what left the warehouse, and with whom. Units are fulfilled once (the ledger of
 * the order line), stock is consumed in the same transaction, and a carrier is booked outside any transaction.
 */
#[Api(since: '0.1')]
interface Fulfillments
{
    /**
     * Fulfils units of a placed order and consumes the held stock of its physical lines; services and digital goods
     * leave the stock alone. A repeat with the same idempotency key returns the same document.
     *
     * @throws OrderNotFound
     * @throws OrderNotFulfillable the order is cancelled or completed
     * @throws LineNotFound
     * @throws QuantityExceedsOutstanding fewer units are left to fulfil: nothing is written
     * @throws ReservationNotFound the stock holds fewer units than fulfilled: nothing is written
     * @throws IdempotencyConflict
     */
    public function create(CreateFulfillment $command): FulfillmentView;

    /**
     * Marks the fulfillment shipped. Shipping it again with the same carrier and tracking returns it unchanged.
     *
     * @throws FulfillmentNotFound
     * @throws FulfillmentAlreadyShipped it left with another carrier or tracking number
     */
    public function ship(ShipFulfillment $command): FulfillmentView;

    /**
     * Books the shipment with the carrier of the order's delivery and ships the fulfillment with the carrier's answer.
     * Must be called outside any database transaction. The booking is idempotent at the shipping package by the
     * reference `fulfillment:{id}`, so a repeat after a lost answer is safe. An already booked fulfillment or a
     * manual shipment with no booking intent is returned without a call. An in-flight booking overtaken by a manual
     * shipment is recovered by that same reference, and a conflicting answer is reported on every retry with the
     * booked shipment (including its label) in the exception. Retry with the same dimensions and meta.
     *
     * @throws OperationInsideTransaction
     * @throws FulfillmentNotFound
     * @throws FulfillmentNotBookable
     * @throws UnsupportedContribution no carrier under the one of the delivery
     * @throws FulfillmentAlreadyShipped it was shipped meanwhile with another tracking number; `booking` preserves the carrier's answer
     */
    public function book(BookFulfillment $command): FulfillmentView;

    /**
     * @throws FulfillmentNotFound
     * @throws FulfillmentNotShipped
     */
    public function markDelivered(MarkFulfillmentDelivered $command): FulfillmentView;

    /** @throws FulfillmentNotFound */
    public function find(FulfillmentId $id): FulfillmentView;

    /** @return Page<FulfillmentView> */
    public function list(ListFulfillments $query): Page;
}
