<?php

declare(strict_types=1);

namespace Commerz\Sales\OrderChanges\Data;

use Commerz\Money\Money;
use Commerz\Sales\OrderChanges\Enums\CompensationDirection;
use Commerz\Sales\Orders\Data\OrderId;
use Packman\Attributes\Api;

/** Dry-run preview of an OrderChange: before/after excerpts, tax delta, and signed amountDelta without DB mutation. */
#[Api(since: '0.1')]
final readonly class OrderChangePreview
{
    /** @param  list<OrderChangeActionView>  $actions */
    public function __construct(
        public OrderChangeId $changeId,
        public OrderId $orderId,
        public int $orderVersion,
        public OrderExcerpt $excerptBefore,
        public OrderExcerpt $excerptAfter,
        public Money $subtotalDelta,
        public Money $discountDelta,
        public Money $deliveryDelta,
        public Money $taxDelta,
        public Money $amountDelta,
        public CompensationDirection $compensationDirection,
        public string $quoteFingerprint,
        public array $actions,
    ) {}
}
