<?php

declare(strict_types=1);

namespace Commerz\Sales\Payments\Data;

use Commerz\Attributes\Api;
use Commerz\Sales\Payments\Enums\PaymentEventOutcome;
use Commerz\Sales\Payments\Enums\PaymentStatus;

/** How a provider event was taken: `duplicate` when it was recorded before (with the outcome it had then). */
#[Api(since: '0.1')]
final readonly class PaymentEventResult
{
    /** @param  PaymentStatus  $status  the status of the payment now */
    public function __construct(
        public PaymentId $payment,
        public bool $duplicate,
        public PaymentEventOutcome $outcome,
        public PaymentStatus $status,
    ) {}
}
