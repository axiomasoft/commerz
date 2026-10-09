<?php

declare(strict_types=1);

namespace Commerz\Ports\Stock\Contracts;

use Commerz\Attributes\Api;
use Commerz\Ports\Stock\Data\RestockStock;

/**
 * Puts returned units back on hand (REF-01: stock returns only through an explicit restock, never implicitly).
 * Joins the caller's database transaction on the Commerz connection (or opens its own when there is none), so an
 * accepted return commits or rolls back together with its restock.
 */
#[Api(since: '0.1')]
interface StockReturns
{
    /** Idempotent per reference: restocking a reference that was already restocked changes nothing. */
    public function restock(RestockStock $command): void;
}
