<?php

declare(strict_types=1);

namespace Commerz\Shipping\Bookings\Internal;

use Commerz\Ports\Delivery\Data\BookedShipment;

/**
 * What the claim transaction decided: `create` and `recover` hold the lease of `attempt`, so only this process may
 * call the carrier and finish the row; `done` is the shipment booked before with the same payload.
 */
final readonly class BookingClaim
{
    public const string CREATE = 'create';

    public const string RECOVER = 'recover';

    public const string DONE = 'done';

    /** @param  self::CREATE|self::RECOVER|self::DONE  $mode */
    private function __construct(
        public string $id,
        public string $reference,
        public int $attempt,
        public string $mode,
        public ?BookedShipment $booked = null,
    ) {}

    public static function create(string $id, string $reference, int $attempt): self
    {
        return new self($id, $reference, $attempt, self::CREATE);
    }

    public static function recover(string $id, string $reference, int $attempt): self
    {
        return new self($id, $reference, $attempt, self::RECOVER);
    }

    public static function done(string $id, string $reference, int $attempt, BookedShipment $booked): self
    {
        return new self($id, $reference, $attempt, self::DONE, $booked);
    }
}
