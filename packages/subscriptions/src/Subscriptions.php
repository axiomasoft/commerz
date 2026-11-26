<?php

declare(strict_types=1);

namespace Commerz\Subscriptions;

use Commerz\Subscriptions\Billing\Contracts\BillingSchedule;
use Commerz\Subscriptions\Lifecycle\Contracts\SubscriptionContracts;
use Commerz\Subscriptions\Renewals\Contracts\RenewalOrders;
use Packman\Attributes\Api;

/**
 * Root of the subscriptions API: accessors for subscription contracts, billing schedule, and renewal orders.
 */
#[Api(since: '0.1')]
final readonly class Subscriptions
{
    public function __construct(
        private SubscriptionContracts $contracts,
        private BillingSchedule $billing,
        private RenewalOrders $renewals,
    ) {}

    public function contracts(): SubscriptionContracts
    {
        return $this->contracts;
    }

    public function billing(): BillingSchedule
    {
        return $this->billing;
    }

    public function renewals(): RenewalOrders
    {
        return $this->renewals;
    }
}
