<?php

declare(strict_types=1);

namespace Commerz\B2b\PaymentTerms\Data;

use Commerz\Money\Money;
use Commerz\Money\Rate;
use DateTimeImmutable;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class ScheduledInstallment
{
    public function __construct(
        public string $label,
        public Rate $share,
        public Money $amount,
        public int $dueInDays,
        public DateTimeImmutable $dueAt,
        public bool $isDeposit,
    ) {}
}
