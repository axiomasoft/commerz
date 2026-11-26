<?php

declare(strict_types=1);

namespace Commerz\Ports\Stock\Contracts;

use Commerz\Ports\Stock\Data\RestockStock;
use Commerz\Ports\Stock\Data\WriteOffStock;
use Packman\Attributes\Api;

/**
 * Puts returned units back on hand (REF-01: stock returns only through an explicit restock, never implicitly) or
 * records damaged returned units as a `write_off` movement without ever increasing sellable `on_hand` (INV-INV-002).
 * Joins the caller's database transaction on the Commerz connection (or opens its own when there is none), so an
 * accepted return commits or rolls back together with its stock disposition.
 */
#[Api(since: '0.1')]
interface StockReturns
{
    /** Idempotent per reference: restocking a reference that was already restocked changes nothing. */
    public function restock(RestockStock $command): void;

    /**
     * Records damaged returned units as a `write_off` movement without ever increasing sellable `on_hand` (INV-INV-002).
     * Idempotent per reference: writing off a reference that was already written off changes nothing.
     */
    public function writeOff(WriteOffStock $command): void;
}
