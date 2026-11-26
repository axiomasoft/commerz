<?php

declare(strict_types=1);

namespace Commerz\Shipping\Packing\Data;

use Commerz\Errors\InvalidCommand;
use Packman\Attributes\Api;

/** Physical and volumetric limits of a single parcel box, in integer grams and millimeters. */
#[Api(since: '0.1')]
final readonly class PackingConstraints
{
    public function __construct(
        public int $maxWeightGrams = 30_000,
        public int $volumetricDivisor = 5_000,
        public ?int $maxVolumeMm3 = null,
        public ?int $maxLengthMm = null,
        public ?int $maxWidthMm = null,
        public ?int $maxHeightMm = null,
        public int $tareWeightGrams = 0,
        public bool $useVolumetricWeight = true,
    ) {
        if ($maxWeightGrams < 1) {
            throw new InvalidCommand('maxWeightGrams', 'must be positive', 'shipping.invalid_packing');
        }

        if ($volumetricDivisor < 1) {
            throw new InvalidCommand('volumetricDivisor', 'must be positive', 'shipping.invalid_packing');
        }

        if ($tareWeightGrams < 0 || $tareWeightGrams >= $maxWeightGrams) {
            throw new InvalidCommand('tareWeightGrams', 'must be non-negative and less than maxWeightGrams', 'shipping.invalid_packing');
        }

        foreach ([
            'maxVolumeMm3' => $maxVolumeMm3,
            'maxLengthMm' => $maxLengthMm,
            'maxWidthMm' => $maxWidthMm,
            'maxHeightMm' => $maxHeightMm,
        ] as $field => $limit) {
            if ($limit !== null && $limit < 1) {
                throw new InvalidCommand($field, 'must be positive', 'shipping.invalid_packing');
            }
        }
    }
}
