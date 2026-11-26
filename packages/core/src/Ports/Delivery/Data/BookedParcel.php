<?php

declare(strict_types=1);

namespace Commerz\Ports\Delivery\Data;

use Commerz\Errors\InvalidCommand;
use Packman\Attributes\Api;

/** One booked parcel within a single- or multi-parcel shipment, carrying its own tracking number and label. */
#[Api(since: '0.1')]
final readonly class BookedParcel
{
    /** @param  array<string, scalar>  $meta */
    public function __construct(
        public int $sequence,
        public string $tracking,
        public Parcel $parcel,
        public ?string $labelRef = null,
        public array $meta = [],
    ) {
        if ($sequence < 1) {
            throw new InvalidCommand('sequence', 'must be at least 1', 'delivery.invalid_parcel');
        }

        if ($tracking === '' || strlen($tracking) > 191) {
            throw new InvalidCommand('tracking', 'must be 1..191 bytes', 'delivery.invalid_parcel');
        }
    }
}
