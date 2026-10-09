<?php

declare(strict_types=1);

namespace Commerz\Shipping\Bookings\Internal;

use Closure;
use Commerz\Configuration\CommerzConfig;
use Commerz\Errors\CommerzException;
use Commerz\Errors\IdempotencyConflict;
use Commerz\Identity\Uuid7;
use Commerz\Operations\Contracts\CommittedEvents;
use Commerz\Operations\Contracts\Transactions;
use Commerz\Operations\Fingerprint;
use Commerz\Ports\Delivery\Data\BookedShipment;
use Commerz\Ports\Delivery\Data\BookShipment;
use Commerz\Schema\TableNames;
use Commerz\Shipping\Bookings\Enums\BookingState;
use Commerz\Shipping\Bookings\Events\ShipmentBooked;
use Commerz\Shipping\Bookings\Exceptions\BookingInProgress;
use Commerz\Shipping\Carriers\Exceptions\CarrierRejected;
use Commerz\Shipping\Common\Internal\Clock;
use Commerz\Shipping\Common\Internal\Rows;
use Commerz\Shipping\Common\Internal\ShippingConfig;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use RuntimeException;
use Throwable;

/**
 * SHP-03, D29: the `shipping_bookings` row of a reference is its lease. `claim()` (one transaction) inserts it or locks
 * it and hands out the next attempt; `finish()` (another transaction) records the carrier's outcome only while the
 * row is still `pending` under that attempt, so a process whose lease was taken over can never overwrite the new owner.
 */
final readonly class BookingJournal
{
    private const string OPERATION = 'shipping.book';

    public function __construct(
        private ConnectionResolverInterface $connections,
        private CommerzConfig $config,
        private ShippingConfig $shipping,
        private TableNames $tables,
        private Transactions $transactions,
        private CommittedEvents $events,
    ) {}

    /**
     * Current method validation belongs only to a new operation, or a new attempt after a final refusal (D30).
     * It runs before the claim commits, so failure leaves no new row or lease. Replay and recovery depend on the
     * original journaled payload, never mutable method settings.
     *
     * @param  (Closure(): void)|null  $validateCreate
     *
     * @throws BookingInProgress another process holds a live lease of the reference
     * @throws IdempotencyConflict the reference was booked, or may have been, with another payload
     */
    public function claim(BookShipment $command, ?Closure $validateCreate = null): BookingClaim
    {
        $fingerprint = self::fingerprint($command);

        return $this->transactions->run(function () use ($command, $fingerprint, $validateCreate): BookingClaim {
            $db = $this->db();
            $table = $this->tables->table('shipping_bookings');
            $id = Uuid7::generate();
            $now = Clock::now();

            // ON CONFLICT never raises, so the caller's transaction is not poisoned; a concurrent insert of the same
            // reference waits here until the first claim commits.
            $inserted = $db->select(
                "insert into {$this->wrap($table)} (id, reference, carrier, method_code, fingerprint, state, attempts, claimed_at, created_at, updated_at)"
                ." values (?, ?, ?, ?, ?, 'pending', 1, ?, ?, ?) on conflict (reference) do nothing returning id",
                [$id, $command->reference, $command->carrier, $command->methodCode, $fingerprint, $now, $now, $now],
            );

            if ($inserted !== []) {
                $validateCreate?->__invoke();

                return BookingClaim::create($id, $command->reference, 1);
            }

            $row = $db->table($table)->where('reference', $command->reference)->lockForUpdate()->first()
                ?? throw new RuntimeException("Booking '{$command->reference}' conflicted but cannot be read.");
            $id = Rows::string($row, 'id');
            $attempt = Rows::int($row, 'attempts') + 1;
            $same = Rows::string($row, 'fingerprint') === $fingerprint;

            $state = BookingState::from(Rows::string($row, 'state'));

            if ($state === BookingState::Booked) {
                return $same
                    ? BookingClaim::done($id, $command->reference, $attempt - 1, self::stored($row))
                    : throw new IdempotencyConflict(self::OPERATION, $command->reference);
            }

            if ($state === BookingState::Rejected) {
                // The carrier created nothing: a new attempt may correct the payload.
                $validateCreate?->__invoke();

                $this->lease($id, $attempt, [
                    'carrier' => $command->carrier,
                    'method_code' => $command->methodCode,
                    'fingerprint' => $fingerprint,
                ]);

                return BookingClaim::create($id, $command->reference, $attempt);
            }

            if ($state === BookingState::Pending && $this->leased($row)) {
                throw new BookingInProgress($command->reference);
            }

            // Unknown, or pending under a lapsed lease: the shipment may exist, so the carrier is asked before creating.
            if (! $same) {
                throw new IdempotencyConflict(self::OPERATION, $command->reference);
            }

            $this->lease($id, $attempt);

            return BookingClaim::recover($id, $command->reference, $attempt);
        });
    }

    /**
     * Records the outcome of the claim's attempt: `booked` with the shipment, `rejected` or `unknown` with the error.
     * Dispatches ShipmentBooked after the commit when it records a shipment.
     *
     * @return bool false when the lease was taken over or the row is no longer pending, so nothing was written
     */
    public function finish(BookingClaim $claim, BookingState $state, ?BookedShipment $shipment = null, ?Throwable $error = null): bool
    {
        $now = Clock::now();
        $values = match ($state) {
            BookingState::Booked => self::shipment($shipment ?? throw new RuntimeException('A booked outcome needs the shipment.'), $now),
            BookingState::Rejected, BookingState::Unknown => self::error($error ?? throw new RuntimeException('A failed outcome needs the error.')),
            BookingState::Pending => throw new RuntimeException('A booking cannot finish as pending.'),
        };

        return $this->transactions->run(function () use ($claim, $state, $shipment, $values, $now): bool {
            $updated = $this->db()->table($this->tables->table('shipping_bookings'))
                ->where('id', $claim->id)
                ->where('attempts', $claim->attempt)
                ->where('state', BookingState::Pending->value)
                ->update(['state' => $state->value, 'updated_at' => $now] + $values);

            if ($updated === 0) {
                return false;
            }

            if ($shipment instanceof BookedShipment) {
                $this->events->dispatch(new ShipmentBooked($claim->reference, $shipment->carrier, $shipment->tracking));
            }

            return true;
        });
    }

    /** Everything the carrier is asked to do; a repeat with an equal fingerprint is the same booking. */
    public static function fingerprint(BookShipment $command): string
    {
        $address = $command->destination;
        $parcel = $command->parcel;

        return Fingerprint::of([
            'carrier' => $command->carrier,
            'method' => $command->methodCode,
            'destination' => [
                'country' => $address->country,
                'region' => $address->region,
                'city' => $address->city,
                'postal_code' => $address->postalCode,
                'lines' => $address->lines,
                'recipient' => $address->recipient,
                'phone' => $address->phone,
            ],
            'parcel' => [
                'weight_grams' => $parcel->weightGrams,
                'value' => [$parcel->value->amountMinor, $parcel->value->currency->code],
                'length_mm' => $parcel->lengthMm,
                'width_mm' => $parcel->widthMm,
                'height_mm' => $parcel->heightMm,
            ],
            'meta' => $command->meta,
        ]);
    }

    /** The shipment a booked row recorded. */
    public static function stored(object $row): BookedShipment
    {
        $meta = [];

        foreach (Rows::nullableString($row, 'result') === null ? [] : Rows::json($row, 'result') as $key => $value) {
            $meta[(string) $key] = is_scalar($value) ? $value : throw new RuntimeException("Booking meta '{$key}' is not a scalar.");
        }

        return new BookedShipment(
            Rows::string($row, 'carrier'),
            Rows::string($row, 'tracking'),
            Rows::nullableString($row, 'label_ref'),
            $meta,
        );
    }

    private function leased(object $row): bool
    {
        $since = new DateTimeImmutable('now', new DateTimeZone('UTC'))->modify("-{$this->shipping->bookingLeaseSeconds} seconds");

        return Rows::date($row, 'claimed_at') > $since;
    }

    /** @param  array<string, string>  $changes */
    private function lease(string $id, int $attempt, array $changes = []): void
    {
        $now = Clock::now();

        $this->db()->table($this->tables->table('shipping_bookings'))->where('id', $id)->update([
            'state' => BookingState::Pending->value,
            'attempts' => $attempt,
            'claimed_at' => $now,
            'updated_at' => $now,
        ] + $changes);
    }

    /** @return array<string, string|null> */
    private static function shipment(BookedShipment $shipment, string $now): array
    {
        return [
            'tracking' => $shipment->tracking,
            'label_ref' => $shipment->labelRef,
            'result' => json_encode($shipment->meta, JSON_THROW_ON_ERROR),
            'booked_at' => $now,
            'error_code' => null,
            'error_message' => null,
        ];
    }

    /** @return array{error_code: string, error_message: string} */
    private static function error(Throwable $error): array
    {
        [$code, $message] = match (true) {
            $error instanceof CarrierRejected => [$error->reason, $error->detail],
            $error instanceof CommerzException => [$error->code(), $error->getMessage()],
            default => [$error::class, $error->getMessage()],
        };

        return [
            'error_code' => mb_substr(self::text($code), 0, 128),
            'error_message' => mb_substr(self::text($message), 0, 2000),
        ];
    }

    /** A carrier's error text as PostgreSQL text: valid UTF-8 without NUL. */
    private static function text(string $value): string
    {
        return str_replace("\0", '', mb_scrub($value, 'UTF-8'));
    }

    private function wrap(string $table): string
    {
        return $this->db()->table($table)->getGrammar()->wrapTable($table);
    }

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->config->connection);
    }
}
