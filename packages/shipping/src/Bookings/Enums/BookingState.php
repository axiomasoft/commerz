<?php

declare(strict_types=1);

namespace Commerz\Shipping\Bookings\Enums;

use Commerz\Attributes\Api;

/**
 * `pending` while one process talks to the carrier under a lease; then `booked` (final), `rejected` (the carrier
 * created nothing; booking again may change the payload) or `unknown` (the carrier may have created the shipment;
 * booking again looks it up first).
 */
#[Api(since: '0.1')]
enum BookingState: string
{
    case Pending = 'pending';
    case Booked = 'booked';
    case Rejected = 'rejected';
    case Unknown = 'unknown';
}
