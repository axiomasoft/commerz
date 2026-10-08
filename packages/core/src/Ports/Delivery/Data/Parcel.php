<?php

declare(strict_types=1);

namespace Commerz\Ports\Delivery\Data;

use Commerz\Attributes\Api;
use Commerz\Errors\InvalidCommand;
use Commerz\Money\Money;

#[Api(since: '0.1')]
final readonly class Parcel
{
    /** @param  Money  $value  declared value of the goods */
    public function __construct(
        public int $weightGrams,
        public Money $value,
        public ?int $lengthMm = null,
        public ?int $widthMm = null,
        public ?int $heightMm = null,
    ) {
        if ($weightGrams < 0) {
            throw new InvalidCommand('weightGrams', 'must not be negative', 'delivery.invalid_parcel');
        }

        if ($value->isNegative()) {
            throw new InvalidCommand('value', 'must not be negative', 'delivery.invalid_parcel');
        }

        foreach (['lengthMm' => $lengthMm, 'widthMm' => $widthMm, 'heightMm' => $heightMm] as $field => $size) {
            if ($size !== null && $size < 1) {
                throw new InvalidCommand($field, 'must be positive', 'delivery.invalid_parcel');
            }
        }
    }
}
