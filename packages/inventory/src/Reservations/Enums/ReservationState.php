<?php

declare(strict_types=1);

namespace Commerz\Inventory\Reservations\Enums;

use Commerz\Attributes\Api;

/** `active` while the demand may hold stock; `closed` once nothing is outstanding (released, expired or consumed). */
#[Api(since: '0.1')]
enum ReservationState: string
{
    case Active = 'active';
    case Closed = 'closed';
}
