<?php

declare(strict_types=1);

namespace Commerz\Subscriptions\Lifecycle\Contracts;

use Commerz\Identity\CustomerRef;
use Commerz\Subscriptions\Lifecycle\Data\CreateSubscriptionContract;
use Commerz\Subscriptions\Lifecycle\Data\SubscriptionContract;
use Commerz\Subscriptions\Lifecycle\Data\SubscriptionContractId;
use DateTimeImmutable;
use Packman\Attributes\Api;

/**
 * Manages recurring {@see SubscriptionContract} lifecycles (`active`, `paused`, `past_due`, `canceled`).
 */
#[Api(since: '0.1')]
interface SubscriptionContracts
{
    public function create(CreateSubscriptionContract $command): SubscriptionContract;

    public function find(SubscriptionContractId|string $id): ?SubscriptionContract;

    public function get(SubscriptionContractId|string $id): SubscriptionContract;

    /** @return list<SubscriptionContract> */
    public function forCustomer(CustomerRef $customer): array;

    /** @return list<SubscriptionContract> */
    public function forOriginOrder(string $originOrderRef): array;

    public function pause(SubscriptionContractId|string $id, ?string $reason = null): SubscriptionContract;

    public function resume(SubscriptionContractId|string $id, ?DateTimeImmutable $nextBillingAt = null): SubscriptionContract;

    public function cancel(SubscriptionContractId|string $id, ?string $reason = null): SubscriptionContract;

    public function markPastDue(SubscriptionContractId|string $id, ?string $reason = null): SubscriptionContract;

    public function updatePaymentMethod(SubscriptionContractId|string $id, string $paymentMethodRef): SubscriptionContract;
}
