<?php

declare(strict_types=1);

namespace Commerz\Subscriptions\Billing\Data;

use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Subscriptions\Billing\Enums\BillingCycleStatus;
use Commerz\Subscriptions\Lifecycle\Data\SubscriptionContractId;
use DateTimeImmutable;
use Packman\Attributes\Api;

/**
 * Snapshot of a scheduled, retrying, billed, skipped, canceled, or failed subscription billing cycle.
 */
#[Api(since: '0.1')]
final readonly class BillingCycle
{
    public function __construct(
        public BillingCycleId $id,
        public SubscriptionContractId $contractId,
        public int $cycleNumber,
        public BillingCycleStatus $status,
        public DateTimeImmutable $scheduledAt,
        public ?DateTimeImmutable $nextAttemptAt,
        public int $attempts,
        public Currency $currency,
        public ?Money $billedAmount,
        public ?string $orderRef,
        public ?string $lastError,
        public ?DateTimeImmutable $billedAt,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}
}
