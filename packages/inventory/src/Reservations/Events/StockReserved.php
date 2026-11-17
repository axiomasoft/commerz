<?php

declare(strict_types=1);

namespace Commerz\Inventory\Reservations\Events;

use Commerz\Inventory\Reservations\Data\ReservationView;
use Packman\Attributes\Api;

/** A demand reserved stock in a committed operation (a repeated reserve that wrote nothing raises none). */
#[Api(since: '0.1')]
final readonly class StockReserved
{
    public function __construct(public ReservationView $reservation) {}
}
