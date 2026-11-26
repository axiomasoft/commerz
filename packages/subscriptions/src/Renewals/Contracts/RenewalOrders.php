<?php

declare(strict_types=1);

namespace Commerz\Subscriptions\Renewals\Contracts;

use Commerz\Subscriptions\Lifecycle\Data\SubscriptionContractId;
use Commerz\Subscriptions\Renewals\Data\RenewalOrderId;
use Commerz\Subscriptions\Renewals\Data\RenewalOrderView;
use Packman\Attributes\Api;

/**
 * Read access to generated subscription renewal orders.
 */
#[Api(since: '0.1')]
interface RenewalOrders
{
    public function find(RenewalOrderId|string $id): ?RenewalOrderView;

    public function findByOrderRef(string $orderRef): ?RenewalOrderView;

    /** @return list<RenewalOrderView> ordered by `cycle_number` ascending */
    public function forContract(SubscriptionContractId|string $contractId): array;
}
