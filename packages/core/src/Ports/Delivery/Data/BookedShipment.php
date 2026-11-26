<?php

declare(strict_types=1);

namespace Commerz\Ports\Delivery\Data;

use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class BookedShipment
{
    /**
     * @param  string|null  $labelRef  where the carrier label can be fetched
     * @param  array<string, scalar>  $meta
     * @param  list<BookedParcel>  $parcels  booked parcels with per-parcel tracking numbers for multi-parcel shipments
     */
    public function __construct(
        public string $carrier,
        public string $tracking,
        public ?string $labelRef = null,
        public array $meta = [],
        public array $parcels = [],
    ) {}

    /** @return non-empty-list<string> */
    public function trackingNumbers(): array
    {
        if ($this->parcels === []) {
            return [$this->tracking];
        }

        return array_map(static fn (BookedParcel $parcel): string => $parcel->tracking, $this->parcels);
    }
}
