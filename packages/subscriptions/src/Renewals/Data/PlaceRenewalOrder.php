<?php

declare(strict_types=1);

namespace Commerz\Subscriptions\Renewals\Data;

use Commerz\Context\CommerceContext;
use Commerz\Identity\CustomerRef;
use Commerz\Quotes\Data\QuoteResult;
use Commerz\Sellable\SellingPlan;
use Commerz\Subscriptions\Billing\Data\BillingCycleId;
use Commerz\Subscriptions\Lifecycle\Data\SubscriptionContractId;
use Commerz\Subscriptions\Renewals\Contracts\RenewalOrderCreator;
use DateTimeImmutable;
use Packman\Attributes\Api;

/**
 * Request handed to {@see RenewalOrderCreator} when a recurring billing cycle is due.
 */
#[Api(since: '0.1')]
final readonly class PlaceRenewalOrder
{
    public function __construct(
        public SubscriptionContractId $contractId,
        public BillingCycleId $cycleId,
        public int $cycleNumber,
        public string $idempotencyKey,
        public CommerceContext $context,
        public CustomerRef $customer,
        public ?string $paymentMethodRef,
        public SellingPlan $sellingPlan,
        public QuoteResult $quote,
        public DateTimeImmutable $occurredAt,
    ) {}
}
