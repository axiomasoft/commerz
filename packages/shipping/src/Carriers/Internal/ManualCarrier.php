<?php

declare(strict_types=1);

namespace Commerz\Shipping\Carriers\Internal;

use Commerz\Ports\Delivery\Data\BookedShipment;
use Commerz\Shipping\Carriers\Contracts\Carrier;
use Commerz\Shipping\Carriers\Data\CarrierBooking;
use Packman\Attributes\Internal;

/**
 * Delivery arranged by hand: no network and no state, so booking is always safe to repeat. The tracking number is
 * the one the caller passed in the shipment's `meta['tracking']`, else the shipment's reference.
 */
#[Internal]
final readonly class ManualCarrier implements Carrier
{
    public function key(): string
    {
        return 'manual';
    }

    public function book(CarrierBooking $booking): BookedShipment
    {
        $tracking = $booking->shipment->meta['tracking'] ?? null;

        if (! is_string($tracking) || $tracking === '' || strlen($tracking) > 191) {
            $tracking = $booking->shipment->reference;
        }

        return new BookedShipment($this->key(), $tracking);
    }

    public function find(CarrierBooking $booking): ?BookedShipment
    {
        return null;
    }
}
