<?php

declare(strict_types=1);

namespace Commerz\Inventory\Reservations\Enums;

use Commerz\Attributes\Api;

/** Why held stock went back: an explicit release, or the reservation's `expiresAt` passed. */
#[Api(since: '0.1')]
enum ReleaseCause: string
{
    case Released = 'released';
    case Expired = 'expired';
}
