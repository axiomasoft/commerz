<?php

declare(strict_types=1);

namespace Commerz\Shipping\Bookings\Events;

use Packman\Attributes\Api;

/** A carrier booked the shipment of `$reference` and the journal recorded it in a committed operation; once per reference. */
#[Api(since: '0.1')]
final readonly class ShipmentBooked
{
    /** @var non-empty-list<string> */
    public array $trackingNumbers;

    /** @param  list<string>  $trackingNumbers */
    public function __construct(
        public string $reference,
        public string $carrier,
        public string $tracking,
        array $trackingNumbers = [],
    ) {
        $this->trackingNumbers = $trackingNumbers === [] ? [$tracking] : $trackingNumbers;
    }
}
