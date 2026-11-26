<?php

declare(strict_types=1);

namespace Commerz\Testkit\Contracts;

use Commerz\Errors\CommerzException;
use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Ports\Delivery\Data\Address;
use Commerz\Ports\Delivery\Data\BookedShipment;
use Commerz\Ports\Delivery\Data\BookShipment;
use Commerz\Ports\Delivery\Data\Parcel;
use Commerz\Quotes\Contracts\QuoteStagePolicy;
use Commerz\Quotes\Enums\FailurePolicy;
use Commerz\Shipping\Carriers\Contracts\Carrier;
use Commerz\Shipping\Carriers\Data\CarrierBooking;
use Commerz\Shipping\Carriers\Exceptions\CarrierRejected;
use Packman\Attributes\Api;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Certification contract suite every `Carrier` implementation must pass.
 *
 * Verifies contribution key stability, idempotent booking by shipment reference, multi-parcel tracking invariants,
 * typed `CarrierRejected` error codes, and `FailurePolicy` when exposed.
 *
 * @phpstan-require-extends TestCase
 */
#[Api(since: '0.1')]
trait AssertsCarrierCompliance
{
    abstract protected function carrier(): Carrier;

    protected function carrierCurrency(): Currency
    {
        return Currency::of('USD');
    }

    protected function sampleCarrierBooking(string $reference = 'fulfillment:compliance-1'): CarrierBooking
    {
        $carrier = $this->carrier();
        $currency = $this->carrierCurrency();

        return new CarrierBooking(
            shipment: new BookShipment(
                reference: $reference,
                carrier: $carrier->key(),
                methodCode: 'standard',
                destination: new Address('US', 'US-CA', 'San Francisco', '94107', ['100 Market St'], 'Jane Doe', '+14155550100'),
                parcel: new Parcel(1250, Money::ofMinor(2505, $currency), 300, 200, 100),
            ),
            attempt: 1,
        );
    }

    /** @return list<CarrierBooking> */
    protected function rejectedCarrierBookings(): array
    {
        return [];
    }

    protected function carrierFailurePolicy(): FailurePolicy
    {
        $carrier = $this->carrier();

        return $carrier instanceof QuoteStagePolicy
            ? $carrier->failurePolicy()
            : FailurePolicy::BlockOnFailure;
    }

    #[Test]
    public function carrierKeyIsNonEmptyAndBounded(): void
    {
        $key = $this->carrier()->key();

        self::assertNotSame('', trim($key));
        self::assertLessThanOrEqual(64, strlen($key));
    }

    #[Test]
    public function bookShipmentIsIdempotentByReference(): void
    {
        $carrier = $this->carrier();
        $booking = $this->sampleCarrierBooking('fulfillment:compliance-idempotent-1');

        $first = $carrier->book($booking);
        $second = $carrier->book($booking);

        self::assertSame($carrier->key(), $first->carrier);
        self::assertNotSame('', $first->tracking);
        self::assertSame($first->tracking, $second->tracking);
        self::assertNotEmpty($first->trackingNumbers());
    }

    #[Test]
    public function multiParcelBookingProducesNonEmptyTrackingNumbers(): void
    {
        $carrier = $this->carrier();
        $currency = $this->carrierCurrency();
        $shipment = BookShipment::forParcels(
            reference: 'fulfillment:compliance-multi-1',
            carrier: $carrier->key(),
            methodCode: 'standard',
            destination: new Address('US', 'US-CA', 'San Francisco', '94107', ['100 Market St'], 'Jane Doe', '+14155550100'),
            parcels: [
                new Parcel(500, Money::ofMinor(1005, $currency)),
                new Parcel(750, Money::ofMinor(1500, $currency)),
            ],
        );

        $booked = $carrier->book(new CarrierBooking($shipment, 1));
        $numbers = $booked->trackingNumbers();

        self::assertNotEmpty($numbers);

        foreach ($numbers as $tracking) {
            self::assertNotSame('', trim($tracking));
        }
    }

    #[Test]
    public function findUnknownShipmentReturnsNullOrBookedShipment(): void
    {
        $unknown = $this->sampleCarrierBooking('fulfillment:compliance-unknown-999');
        $found = $this->carrier()->find($unknown);

        self::assertThat(
            $found,
            self::logicalOr(self::isNull(), self::isInstanceOf(BookedShipment::class)),
        );
    }

    #[Test]
    public function rejectedCarrierBookingCarriesTypedErrorCodeAndHonorsFailurePolicy(): void
    {
        $policy = $this->carrierFailurePolicy();
        self::assertContains($policy, [FailurePolicy::BlockOnFailure, FailurePolicy::IgnoreOnFailure]);

        foreach ($this->rejectedCarrierBookings() as $rejected) {
            try {
                $this->carrier()->book($rejected);
                self::fail('Expected CarrierRejected for rejectedCarrierBookings().');
            } catch (CarrierRejected $exception) {
                self::assertInstanceOf(CommerzException::class, $exception);
                self::assertSame('shipping.carrier_rejected', $exception->code());
                $descriptor = $exception->errorDescriptor();
                self::assertFalse($descriptor->retryable);
            }
        }
    }
}
