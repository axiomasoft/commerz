<?php

declare(strict_types=1);

namespace Commerz\Shipping\Bookings\Data;

use Commerz\Ports\Delivery\Data\BookedParcel;
use Commerz\Shipping\Bookings\Enums\BookingState;
use DateTimeImmutable;
use Packman\Attributes\Api;

/** A journal entry of a shipment booking; `errorCode` and `errorMessage` describe the last rejection or unknown outcome. */
#[Api(since: '0.1')]
final readonly class BookingView
{
    /**
     * @param  array<array-key, mixed>  $meta  the carrier's `BookedShipment::$meta` of a booked shipment
     * @param  list<BookedParcel>  $parcels  individual booked parcels for multi-parcel shipments
     */
    public function __construct(
        public BookingId $id,
        public string $reference,
        public string $carrier,
        public string $methodCode,
        public BookingState $state,
        public int $attempts,
        public ?string $tracking,
        public ?string $labelRef,
        public array $meta,
        public ?string $errorCode,
        public ?string $errorMessage,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public ?DateTimeImmutable $bookedAt,
        public array $parcels = [],
    ) {}

    /** @return list<string> */
    public function trackingNumbers(): array
    {
        if ($this->parcels !== []) {
            return array_map(static fn (BookedParcel $parcel): string => $parcel->tracking, $this->parcels);
        }

        return $this->tracking === null ? [] : [$this->tracking];
    }
}
