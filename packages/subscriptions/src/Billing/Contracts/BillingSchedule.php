<?php

declare(strict_types=1);

namespace Commerz\Subscriptions\Billing\Contracts;

use Commerz\Subscriptions\Billing\Data\BillingCycle;
use Commerz\Subscriptions\Billing\Data\BillingCycleId;
use Commerz\Subscriptions\Billing\Data\BillingRunReport;
use Commerz\Subscriptions\Lifecycle\Data\SubscriptionContractId;
use DateTimeImmutable;
use Packman\Attributes\Api;

/**
 * Inspects and executes {@see BillingCycle} schedules, retry dunning policies, and automatic renewal order creation.
 */
#[Api(since: '0.1')]
interface BillingSchedule
{
    public function find(BillingCycleId|string $id): ?BillingCycle;

    public function get(BillingCycleId|string $id): BillingCycle;

    /** @return list<BillingCycle> ordered by `cycle_number` ascending */
    public function forContract(SubscriptionContractId|string $contractId): array;

    /** @return list<BillingCycle> due cycles whose `next_attempt_at <= $asOf` (or `Clock::now()`) */
    public function dueCycles(?DateTimeImmutable $asOf = null, ?int $limit = null): array;

    public function processDue(?int $limit = null): BillingRunReport;

    public function processCycle(BillingCycleId|string $cycleId): BillingCycle;
}
