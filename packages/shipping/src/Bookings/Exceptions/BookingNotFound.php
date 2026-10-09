<?php

declare(strict_types=1);

namespace Commerz\Shipping\Bookings\Exceptions;

use Commerz\Attributes\Api;
use Commerz\Errors\NotFound;

#[Api(since: '0.1')]
final class BookingNotFound extends NotFound
{
    public function __construct(string $reference)
    {
        parent::__construct('Shipment booking', $reference, 'shipping.booking_not_found');
    }
}
