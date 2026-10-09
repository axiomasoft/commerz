<?php

declare(strict_types=1);

namespace Commerz\Shipping\Bookings\Internal;

use Commerz\Errors\IdempotencyConflict;
use Commerz\Errors\UnsupportedContribution;
use Commerz\Operations\Contracts\Transactions;
use Commerz\Plugins\Contracts\Contributions;
use Commerz\Ports\Delivery\Contracts\ShipmentBooking;
use Commerz\Ports\Delivery\Data\BookedShipment;
use Commerz\Ports\Delivery\Data\BookShipment;
use Commerz\Shipping\Bookings\Enums\BookingState;
use Commerz\Shipping\Bookings\Exceptions\BookingInProgress;
use Commerz\Shipping\Bookings\Exceptions\BookingInsideTransaction;
use Commerz\Shipping\Bookings\Exceptions\BookingOutcomeUnknown;
use Commerz\Shipping\Bookings\Exceptions\CarrierMismatch;
use Commerz\Shipping\Carriers\Contracts\Carrier;
use Commerz\Shipping\Carriers\Data\CarrierBooking;
use Commerz\Shipping\Carriers\Exceptions\CarrierRejected;
use Commerz\Shipping\Carriers\Exceptions\InvalidCarrierResult;
use Commerz\Shipping\Methods\Contracts\Methods;
use Commerz\Shipping\Methods\Enums\MethodStatus;
use Commerz\Shipping\Methods\Exceptions\MethodArchived;
use Commerz\Shipping\Methods\Exceptions\MethodNotFound;
use JsonException;
use Throwable;

/**
 * The only caller of `Carrier::book()` and `Carrier::find()` (SHP-03, SHP-04, D29). The intent is journaled before
 * the network; the carrier is called outside any database transaction by the one process that holds the lease; an
 * outcome that is not a booking or a final refusal is `unknown`, and the next attempt asks the carrier with `find()`
 * before it may create the shipment again.
 */
final readonly class CarrierShipmentBooking implements ShipmentBooking
{
    public function __construct(
        private Transactions $transactions,
        private Methods $methods,
        private Contributions $contributions,
        private BookingJournal $journal,
    ) {}

    /**
     * @throws BookingInsideTransaction
     * @throws MethodNotFound|MethodArchived
     * @throws CarrierMismatch
     * @throws UnsupportedContribution
     * @throws BookingInProgress
     * @throws IdempotencyConflict
     * @throws CarrierRejected
     * @throws BookingOutcomeUnknown
     * @throws InvalidCarrierResult the carrier answered with something that is not a valid booking, which it may have made
     */
    public function book(BookShipment $command): BookedShipment
    {
        if ($this->transactions->inTransaction()) {
            throw new BookingInsideTransaction($command->reference);
        }

        $claim = $this->journal->claim($command, fn () => $this->validateCreate($command));

        if ($claim->booked instanceof BookedShipment) {
            return $claim->booked;
        }

        try {
            $carrier = $this->contributions->get(Carrier::KIND, $command->carrier, Carrier::class);
        } catch (Throwable $e) {
            // Recovery needs the original carrier. If it is unavailable, preserve a retryable unknown outcome.
            $this->journal->finish($claim, BookingState::Unknown, error: $e);

            throw $e;
        }

        $booking = new CarrierBooking($command, $claim->attempt);

        if ($claim->mode === BookingClaim::RECOVER) {
            try {
                $found = $carrier->find($booking);
            } catch (Throwable $e) {
                $this->unknown($claim, $command, $e);
            }

            if ($found instanceof BookedShipment) {
                return $this->booked($claim, $carrier, $found);
            }
        }

        try {
            $booked = $carrier->book($booking);
        } catch (CarrierRejected $e) {
            $this->journal->finish($claim, BookingState::Rejected, error: $e);

            throw $e;
        } catch (Throwable $e) {
            $this->unknown($claim, $command, $e);
        }

        return $this->booked($claim, $carrier, $booked);
    }

    /**
     * @throws MethodNotFound|MethodArchived
     * @throws CarrierMismatch
     * @throws UnsupportedContribution
     */
    private function validateCreate(BookShipment $command): void
    {
        $method = $this->methods->findByCode($command->methodCode);

        if ($method->status === MethodStatus::Archived) {
            throw new MethodArchived($method->code);
        }

        if ($method->carrier !== $command->carrier) {
            throw new CarrierMismatch($method->code, $method->carrier, $command->carrier);
        }

        if (! $this->contributions->has(Carrier::KIND, $command->carrier)) {
            throw new UnsupportedContribution(Carrier::KIND, $command->carrier, 'no carrier is installed under this key');
        }
    }

    /** Records the shipment; one the package cannot trust leaves the booking unknown, since the carrier may have made it. */
    private function booked(BookingClaim $claim, Carrier $carrier, BookedShipment $shipment): BookedShipment
    {
        try {
            self::validate($carrier->key(), $shipment);
        } catch (InvalidCarrierResult $e) {
            $this->journal->finish($claim, BookingState::Unknown, error: $e);

            throw $e;
        }

        // When the lease was taken over meanwhile nothing is written, yet the shipment is real: the new owner finds it.
        $this->journal->finish($claim, BookingState::Booked, $shipment);

        return $shipment;
    }

    private function unknown(BookingClaim $claim, BookShipment $command, Throwable $error): never
    {
        $this->journal->finish($claim, BookingState::Unknown, error: $error);

        throw new BookingOutcomeUnknown($command->reference, $command->carrier, $error);
    }

    /** @throws InvalidCarrierResult */
    private static function validate(string $carrier, BookedShipment $shipment): void
    {
        $reason = match (true) {
            $shipment->carrier !== $carrier => "the booking is by '{$shipment->carrier}'",
            ! self::isText($shipment->tracking) => 'the tracking number must be 1..191 bytes of UTF-8',
            $shipment->labelRef !== null && ! self::isText($shipment->labelRef) => 'the label reference must be 1..191 bytes of UTF-8',
            ! self::isMeta($shipment->meta) => 'meta must map string keys to scalars representable as PostgreSQL JSON without NUL',
            default => null,
        };

        if ($reason !== null) {
            throw new InvalidCarrierResult($carrier, $reason);
        }
    }

    private static function isText(string $value): bool
    {
        return $value !== '' && strlen($value) <= 191 && mb_check_encoding($value, 'UTF-8') && ! str_contains($value, "\0");
    }

    /** @param  array<array-key, mixed>  $meta */
    private static function isMeta(array $meta): bool
    {
        foreach ($meta as $key => $value) {
            if (! is_string($key) || ! is_scalar($value)
                || str_contains($key, "\0")
                || (is_string($value) && str_contains($value, "\0"))) {
                return false;
            }
        }

        try {
            json_encode($meta, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return false;
        }

        return true;
    }
}
