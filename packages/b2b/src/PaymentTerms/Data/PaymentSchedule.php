<?php

declare(strict_types=1);

namespace Commerz\B2b\PaymentTerms\Data;

use Commerz\Money\Money;
use DateTimeImmutable;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class PaymentSchedule
{
    /** @param list<ScheduledInstallment> $installments */
    public function __construct(
        public Money $totalAmount,
        public Money $dueNowAmount,
        public Money $deferredAmount,
        public DateTimeImmutable $finalDueAt,
        public array $installments,
    ) {}
}
