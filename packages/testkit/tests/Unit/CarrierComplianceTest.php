<?php

declare(strict_types=1);

namespace Commerz\Testkit\Tests\Unit;

use Commerz\Money\Money;
use Commerz\Ports\Delivery\Data\Address;
use Commerz\Ports\Delivery\Data\BookedParcel;
use Commerz\Ports\Delivery\Data\BookedShipment;
use Commerz\Ports\Delivery\Data\BookShipment;
use Commerz\Ports\Delivery\Data\Parcel;
use Commerz\Shipping\Carriers\Contracts\Carrier;
use Commerz\Shipping\Carriers\Data\CarrierBooking;
use Commerz\Shipping\Carriers\Exceptions\CarrierRejected;
use Commerz\Testkit\Contracts\AssertsCarrierCompliance;
use PHPUnit\Framework\TestCase;

final class CarrierComplianceTest extends TestCase
{
    use AssertsCarrierCompliance;

    private ?Carrier $carrierInstance = null;

    protected function carrier(): Carrier
    {
        return $this->carrierInstance ??= new class implements Carrier
        {
            /** @var array<string, BookedShipment> */
            private array $booked = [];

            public function key(): string
            {
                return 'test.carrier';
            }

            public function book(CarrierBooking $booking): BookedShipment
            {
                if (($booking->shipment->meta['force_reject'] ?? false) === true) {
                    throw new CarrierRejected($this->key(), 'invalid_destination', 'Destination rejected in test.');
                }

                $reference = $booking->shipment->reference;

                if (isset($this->booked[$reference])) {
                    return $this->booked[$reference];
                }

                $parcels = $booking->shipment->parcels;

                if (count($parcels) <= 1) {
                    return $this->booked[$reference] = new BookedShipment($this->key(), 'TRK-'.$reference);
                }

                $bookedParcels = [];

                foreach ($parcels as $index => $parcel) {
                    $seq = $index + 1;
                    $bookedParcels[] = new BookedParcel($seq, "TRK-{$reference}-P{$seq}", $parcel);
                }

                return $this->booked[$reference] = new BookedShipment(
                    carrier: $this->key(),
                    tracking: $bookedParcels[0]->tracking,
                    parcels: $bookedParcels,
                );
            }

            public function find(CarrierBooking $booking): ?BookedShipment
            {
                return $this->booked[$booking->shipment->reference] ?? null;
            }
        };
    }

    /** @return list<CarrierBooking> */
    protected function rejectedCarrierBookings(): array
    {
        return [
            new CarrierBooking(
                shipment: new BookShipment(
                    reference: 'fulfillment:compliance-rejected-1',
                    carrier: $this->carrier()->key(),
                    methodCode: 'standard',
                    destination: new Address('US', 'US-CA', 'San Francisco', '94107', ['100 Market St'], 'Jane Doe', '+14155550100'),
                    parcel: new Parcel(500, Money::ofMinor(1000, $this->carrierCurrency())),
                    meta: ['force_reject' => true],
                ),
                attempt: 1,
            ),
        ];
    }
}
