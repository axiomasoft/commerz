<?php

declare(strict_types=1);

namespace Commerz\Ports\Delivery\Data;

use Commerz\Errors\InvalidCommand;
use Packman\Attributes\Api;

/** Physical capacity limits of a parcel locker compartment or pickup counter, in integer grams and millimeters. */
#[Api(since: '0.1')]
final readonly class PickupPointLimits
{
    public function __construct(
        public ?int $maxWeightGrams = null,
        public ?int $maxLengthMm = null,
        public ?int $maxWidthMm = null,
        public ?int $maxHeightMm = null,
    ) {
        foreach ([
            'maxWeightGrams' => $maxWeightGrams,
            'maxLengthMm' => $maxLengthMm,
            'maxWidthMm' => $maxWidthMm,
            'maxHeightMm' => $maxHeightMm,
        ] as $field => $limit) {
            if ($limit !== null && $limit < 1) {
                throw new InvalidCommand($field, 'must be positive', 'delivery.invalid_pickup_point');
            }
        }
    }

    /** Whether a parcel fits within the weight limit and 3D compartment dimensions (allowing 90° orthogonal rotation by default). */
    public function fitsParcel(Parcel $parcel, bool $allowRotation = true): bool
    {
        if ($this->maxWeightGrams !== null && $parcel->weightGrams > $this->maxWeightGrams) {
            return false;
        }

        if ($this->maxLengthMm === null && $this->maxWidthMm === null && $this->maxHeightMm === null) {
            return true;
        }

        if (! $allowRotation) {
            return ($this->maxLengthMm === null || $parcel->lengthMm === null || $parcel->lengthMm <= $this->maxLengthMm)
                && ($this->maxWidthMm === null || $parcel->widthMm === null || $parcel->widthMm <= $this->maxWidthMm)
                && ($this->maxHeightMm === null || $parcel->heightMm === null || $parcel->heightMm <= $this->maxHeightMm);
        }

        $parcelDims = array_values(array_filter(
            [$parcel->lengthMm, $parcel->widthMm, $parcel->heightMm],
            static fn (?int $dim): bool => $dim !== null,
        ));

        if ($parcelDims === []) {
            return true;
        }

        $lockerDims = [
            $this->maxLengthMm ?? PHP_INT_MAX,
            $this->maxWidthMm ?? PHP_INT_MAX,
            $this->maxHeightMm ?? PHP_INT_MAX,
        ];

        sort($parcelDims, SORT_NUMERIC);
        sort($lockerDims, SORT_NUMERIC);

        $offset = 3 - count($parcelDims);

        return array_all($parcelDims, fn ($size, $index): bool => $size <= $lockerDims[$offset + $index]);
    }

    /**
     * Checks both the aggregate parcel and any individual item parcels so an oversized item is rejected even when
     * aggregate bounding dimensions have not been packed yet.
     *
     * @param  list<Parcel>  $itemParcels
     */
    public function fitsAll(Parcel $parcel, array $itemParcels = [], bool $allowRotation = true): bool
    {
        if (! $this->fitsParcel($parcel, $allowRotation)) {
            return false;
        }

        return array_all($itemParcels, fn (Parcel $item): bool => $this->fitsParcel($item, $allowRotation));
    }
}
