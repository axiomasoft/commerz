<?php

declare(strict_types=1);

namespace Commerz\Shipping\Carriers\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Ports\Delivery\Data\BookShipment;
use Packman\Attributes\Api;

/** What a carrier is asked to book: the shipment and the number of this attempt (1 for the first). */
#[Api(since: '0.1')]
final readonly class CarrierBooking
{
    public function __construct(public BookShipment $shipment, public int $attempt)
    {
        if ($attempt < 1) {
            throw new InvalidCommand('attempt', 'must be at least 1', 'shipping.invalid_booking');
        }
    }
}
