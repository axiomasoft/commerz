<?php

declare(strict_types=1);

namespace Commerz\Sales\Payments\Exceptions;

use Commerz\Attributes\Api;
use Commerz\Errors\CommerzException;
use Commerz\Errors\ErrorCategory;

/** Another process is asking the gateway about this payment under a live lease; try again after it. */
#[Api(since: '0.1')]
final class PaymentInProgress extends CommerzException
{
    public function __construct(public readonly string $payment)
    {
        parent::__construct("Payment '{$payment}' is being initiated by another process.");
    }

    public function code(): string
    {
        return 'sales.payment_in_progress';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return ['payment' => $this->payment];
    }
}
