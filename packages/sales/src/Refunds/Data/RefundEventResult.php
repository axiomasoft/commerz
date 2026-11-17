<?php

declare(strict_types=1);

namespace Commerz\Sales\Refunds\Data;

use Commerz\Sales\Payments\Enums\PaymentEventOutcome;
use Commerz\Sales\Refunds\Enums\RefundStatus;
use Packman\Attributes\Api;

/** How a provider event about a refund was taken: `duplicate` when it was recorded before (with the outcome it had then). */
#[Api(since: '0.1')]
final readonly class RefundEventResult
{
    /** @param  RefundStatus  $status  the status of the refund now */
    public function __construct(
        public RefundId $refund,
        public bool $duplicate,
        public PaymentEventOutcome $outcome,
        public RefundStatus $status,
    ) {}
}
