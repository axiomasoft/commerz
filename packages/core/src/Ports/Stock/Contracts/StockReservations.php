<?php

declare(strict_types=1);

namespace Commerz\Ports\Stock\Contracts;

use Commerz\Attributes\Api;
use Commerz\Ports\Stock\Data\ConsumeReservation;
use Commerz\Ports\Stock\Data\ReleaseReservation;
use Commerz\Ports\Stock\Data\ReservationResult;
use Commerz\Ports\Stock\Data\ReserveStock;
use Commerz\Ports\Stock\Exceptions\InsufficientStock;
use Commerz\Ports\Stock\Exceptions\ReservationNotFound;

/**
 * Holds stock for a demand (`order:{id}`). Every mutation joins the caller's database transaction on the Commerz
 * connection (or opens its own when there is none), owns its locking and makes no network calls, so an order
 * placement commits or rolls back reservations together with the order.
 */
#[Api(since: '0.1')]
interface StockReservations
{
    /**
     * All lines or nothing. Idempotent per demand: reserving a demand that already holds stock returns what it holds.
     *
     * @throws InsufficientStock
     */
    public function reserve(ReserveStock $command): ReservationResult;

    /** Gives held stock back, by demand or one of its sellables. Releasing what is not held is a no-op. */
    public function release(ReleaseReservation $command): void;

    /**
     * Turns held stock into a sale (stock leaves on fulfilment); may be partial.
     *
     * @throws ReservationNotFound the demand holds less than `$quantity` of the sellable
     */
    public function consume(ConsumeReservation $command): void;
}
