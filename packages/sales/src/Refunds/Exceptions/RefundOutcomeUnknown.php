<?php

declare(strict_types=1);

namespace Commerz\Sales\Refunds\Exceptions;

use Commerz\Attributes\Api;
use Commerz\Errors\CommerzException;
use Commerz\Errors\ErrorCategory;
use Throwable;

/**
 * The gateway did not answer for certain (a timeout, a broken connection): the money may be on its way back. The
 * refund is `unknown`, never `failed`, and keeps its amount from being refunded again; `Refunds::reconcile` asks the
 * gateway later (PAY-02).
 */
#[Api(since: '0.1')]
final class RefundOutcomeUnknown extends CommerzException
{
    public function __construct(public readonly string $refund, public readonly string $gateway, ?Throwable $previous = null)
    {
        parent::__construct("The outcome of refund '{$refund}' at gateway '{$gateway}' is unknown; reconcile it.", previous: $previous);
    }

    public function code(): string
    {
        return 'sales.refund_outcome_unknown';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Integration;
    }

    public function context(): array
    {
        return ['refund' => $this->refund, 'gateway' => $this->gateway];
    }
}
