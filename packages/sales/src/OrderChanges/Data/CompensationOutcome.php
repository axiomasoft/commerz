<?php

declare(strict_types=1);

namespace Commerz\Sales\OrderChanges\Data;

use Commerz\Money\Money;
use Commerz\Sales\OrderChanges\Enums\CompensationStrategy;
use Commerz\Sales\Payments\Data\PaymentId;
use Commerz\Sales\Refunds\Data\RefundId;
use Packman\Attributes\Api;

/** Result of the financial compensating workflow executed for a confirmed OrderChange. */
#[Api(since: '0.1')]
final readonly class CompensationOutcome
{
    /** @param  array<string, scalar|null>  $details */
    public function __construct(
        public CompensationStrategy $strategy,
        public Money $amount,
        public ?PaymentId $paymentId = null,
        public ?RefundId $refundId = null,
        public ?string $storedValueAccountId = null,
        public ?string $storedValueJournalId = null,
        public array $details = [],
    ) {}
}
