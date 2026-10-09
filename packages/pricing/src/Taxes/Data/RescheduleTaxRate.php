<?php

declare(strict_types=1);

namespace Commerz\Pricing\Taxes\Data;

use Commerz\Attributes\Api;
use Commerz\Pricing\Common\Internal\Windows;
use DateTimeImmutable;

/** Moves a rate to another window; a null bound is open. */
#[Api(since: '0.1')]
final readonly class RescheduleTaxRate
{
    public function __construct(
        public TaxRateId $rate,
        public ?DateTimeImmutable $validFrom = null,
        public ?DateTimeImmutable $validUntil = null,
    ) {
        Windows::assertOrdered($validFrom, $validUntil);
    }
}
