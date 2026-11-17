<?php

declare(strict_types=1);

namespace Commerz\Shipping\Carriers\Contracts;

use Commerz\Ports\Delivery\Data\BookedShipment;
use Commerz\Shipping\Carriers\Data\CarrierBooking;
use Commerz\Shipping\Carriers\Exceptions\CarrierRejected;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/** A shipping company: books shipments on request of `ShipmentBooking`. */
#[Spi(kind: SpiKind::Implement, since: '0.1')]
interface Carrier
{
    public const string KIND = 'carrier';

    /** Contribution key, e.g. `manual` or `cdek`. */
    public function key(): string;

    /**
     * Creates the shipment at the carrier. Called OUTSIDE a database transaction and free to use the network. Pass
     * `$booking->shipment->reference` to the carrier as the sender's number when its API allows, so `find()` can find it.
     *
     * @throws CarrierRejected a final refusal: the carrier certainly created nothing
     *
     * Any other exception means the outcome is unknown (a timeout, a broken connection): the journal turns `unknown`.
     */
    public function book(CarrierBooking $booking): BookedShipment;

    /**
     * Looks for a shipment created earlier under the same reference (recovery after `unknown` or a lapsed lease).
     *
     * @return BookedShipment|null null only when the carrier confirms there is none, so creating one is safe; an exception means the outcome is still unknown
     */
    public function find(CarrierBooking $booking): ?BookedShipment;
}
