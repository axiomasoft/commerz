<?php

declare(strict_types=1);

namespace Commerz\Shipping\Carriers\Internal;

use Commerz\Ports\Delivery\Data\BookedParcel;
use Commerz\Ports\Delivery\Data\BookedShipment;
use Commerz\Shipping\Carriers\Contracts\Carrier;
use Commerz\Shipping\Carriers\Data\CarrierBooking;
use Packman\Attributes\Internal;

/**
 * Delivery arranged by hand: no network and no state, so booking is always safe to repeat. The tracking number is
 * the one the caller passed in the shipment's `meta['tracking']`, else the shipment's reference. Multi-parcel
 * shipments receive distinct per-parcel tracking numbers (`<base>-P1`, `<base>-P2`, ...).
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

        $parcels = $booking->shipment->parcels;

        if (count($parcels) <= 1) {
            return new BookedShipment($this->key(), $tracking);
        }

        $bookedParcels = [];
        $prefixMax = 180;
        $base = strlen($tracking) > $prefixMax ? substr($tracking, 0, $prefixMax) : $tracking;

        foreach ($parcels as $index => $parcel) {
            $seq = $index + 1;
            $custom = $booking->shipment->meta["tracking_{$seq}"] ?? null;
            $parcelTracking = is_string($custom) && $custom !== '' && strlen($custom) <= 191
                ? $custom
                : "{$base}-P{$seq}";

            $bookedParcels[] = new BookedParcel(
                sequence: $seq,
                tracking: $parcelTracking,
                parcel: $parcel,
            );
        }

        return new BookedShipment(
            carrier: $this->key(),
            tracking: $bookedParcels[0]->tracking,
            parcels: $bookedParcels,
        );
    }

    public function find(CarrierBooking $booking): ?BookedShipment
    {
        return null;
    }
}
