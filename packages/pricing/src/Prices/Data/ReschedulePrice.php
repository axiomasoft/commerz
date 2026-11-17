<?php

declare(strict_types=1);

namespace Commerz\Pricing\Prices\Data;

use Commerz\Pricing\Common\Internal\Windows;
use DateTimeImmutable;
use Packman\Attributes\Api;

/** Moves a price to another window; a null bound is open. */
#[Api(since: '0.1')]
final readonly class ReschedulePrice
{
    public function __construct(
        public PriceId $price,
        public ?DateTimeImmutable $validFrom = null,
        public ?DateTimeImmutable $validUntil = null,
    ) {
        Windows::assertOrdered($validFrom, $validUntil);
    }
}
