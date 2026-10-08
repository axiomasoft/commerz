<?php

declare(strict_types=1);

namespace Commerz\Ports\Stock\Exceptions;

use Commerz\Attributes\Api;
use Commerz\Errors\NotFound;

/** The demand holds no (or not enough) reserved stock of the sellable. */
#[Api(since: '0.1')]
final class ReservationNotFound extends NotFound
{
    public function __construct(string $demand, string $sellable)
    {
        parent::__construct('Reservation', "{$demand}/{$sellable}", 'stock.reservation_not_found');
    }
}
