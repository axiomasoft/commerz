<?php

declare(strict_types=1);

namespace Commerz\Inventory\Reservations\Contracts;

use Commerz\Inventory\Allocation\Exceptions\InvalidAllocationPlan;
use Commerz\Inventory\Reservations\Data\ExpireReservations;
use Commerz\Inventory\Reservations\Data\ReservationView;
use Commerz\Inventory\Reservations\Exceptions\ReservationConflict;
use Commerz\Ports\Stock\Contracts\StockReservations;
use Commerz\Ports\Stock\Data\ConsumeReservation;
use Commerz\Ports\Stock\Data\ReservationResult;
use Commerz\Ports\Stock\Data\ReserveStock;
use Commerz\Ports\Stock\Exceptions\InsufficientStock;
use Commerz\Ports\Stock\Exceptions\ReservationNotFound;
use Packman\Attributes\Api;

/**
 * The inventory's stock reservations: the kernel port plus inspection and expiry.
 *
 * A demand has one reservation header. `reserve` places every line through the configured `allocation_strategy` on
 * locked levels, all or nothing. Repeating it while the reservation is live (active and not expired) or after
 * something was consumed returns what it holds and writes nothing; once it is closed or expired with nothing consumed
 * the demand reserves anew as the next generation. A reservation past its `expiresAt` holds nothing: a reserve that needs its stock releases it, and
 * {@see expire()} closes it.
 */
#[Api(since: '0.1')]
interface Reservations extends StockReservations
{
    /**
     * @throws InsufficientStock
     * @throws ReservationConflict the demand is reserved for another channel
     * @throws InvalidAllocationPlan the strategy proposed more than the sources hold
     */
    public function reserve(ReserveStock $command): ReservationResult;

    /**
     * Ships held units from the levels they are held on — units on hand first, then by allocation order — or only
     * from `locationCode`. An untracked or unlimited sellable holds nothing and ships nothing. Not idempotent: the
     * caller's shipping document is the idempotency key.
     *
     * @throws ReservationNotFound the demand holds fewer units of the sellable (there)
     * @throws InsufficientStock a backorder unit is held but not on hand yet
     */
    public function consume(ConsumeReservation $command): void;

    /** @throws ReservationNotFound */
    public function find(string $demand): ReservationView;

    /** @return int reservations closed by this call */
    public function expire(ExpireReservations $command): int;
}
