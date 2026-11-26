<?php

declare(strict_types=1);

namespace Commerz\Subscriptions\Renewals\Data;

use Commerz\Identity\CustomerRef;
use Commerz\Money\Money;
use Commerz\Stores\Data\ChannelId;
use Commerz\Subscriptions\Billing\Data\BillingCycleId;
use Commerz\Subscriptions\Lifecycle\Data\SubscriptionContractId;
use DateTimeImmutable;
use Packman\Attributes\Api;

/**
 * Snapshot of an automatically generated subscription renewal order.
 */
#[Api(since: '0.1')]
final readonly class RenewalOrderView
{
    /**
     * @param  list<array<string, mixed>>  $linesSnapshot
     */
    public function __construct(
        public RenewalOrderId $id,
        public string $orderRef,
        public SubscriptionContractId $contractId,
        public BillingCycleId $cycleId,
        public int $cycleNumber,
        public ChannelId $channelId,
        public CustomerRef $customer,
        public ?string $paymentMethodRef,
        public Money $subtotal,
        public Money $discountTotal,
        public Money $deliveryTotal,
        public Money $taxTotal,
        public Money $total,
        public Money $dueNowAmount,
        public string $idempotencyKey,
        public array $linesSnapshot,
        public DateTimeImmutable $createdAt,
    ) {}
}
