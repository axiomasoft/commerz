<?php

declare(strict_types=1);

namespace Commerz\B2b\Quotes\Data;

use Commerz\Money\Money;
use DateTimeImmutable;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class PlacedB2bOrder
{
    public function __construct(
        public string $orderRef,
        public string $orderNumber,
        public Money $total,
        public ?DateTimeImmutable $paymentDueAt = null,
    ) {}
}
