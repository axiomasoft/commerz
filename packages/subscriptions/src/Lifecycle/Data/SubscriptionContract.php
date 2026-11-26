<?php

declare(strict_types=1);

namespace Commerz\Subscriptions\Lifecycle\Data;

use Commerz\Identity\CustomerRef;
use Commerz\Money\Currency;
use Commerz\Sellable\SellingPlan;
use Commerz\Stores\Data\ChannelId;
use Commerz\Subscriptions\Billing\Data\RetryPolicy;
use Commerz\Subscriptions\Lifecycle\Enums\SubscriptionStatus;
use DateTimeImmutable;
use Packman\Attributes\Api;

/**
 * Snapshot of a recurring subscription contract (`active`, `paused`, `past_due`, `canceled`).
 */
#[Api(since: '0.1')]
final readonly class SubscriptionContract
{
    /**
     * @param  list<SubscriptionLine>  $lines
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public SubscriptionContractId $id,
        public ChannelId $channelId,
        public Currency $currency,
        public string $locale,
        public CustomerRef $customer,
        public SubscriptionStatus $status,
        public SellingPlan $sellingPlan,
        public RetryPolicy $retryPolicy,
        public ?string $paymentMethodRef,
        public ?string $originOrderRef,
        public ?string $originLineId,
        public array $lines,
        public int $completedCycles,
        public int $consecutiveFailures,
        public ?DateTimeImmutable $nextBillingAt,
        public ?DateTimeImmutable $pausedAt,
        public ?DateTimeImmutable $canceledAt,
        public ?string $cancelReason,
        public int $version,
        public array $metadata,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}
}
