<?php

declare(strict_types=1);

namespace Commerz\Sales\Payments\Exceptions;

use Commerz\Errors\CommerzException;
use Commerz\Errors\ErrorCategory;
use Commerz\Sales\Errors\Enums\SalesErrorCode;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory as PackmanErrorCategory;
use Throwable;

/**
 * The gateway did not answer for certain (a timeout, a broken connection): the payment may exist at the provider.
 * It is `unknown`, never `failed`; `Payments::reconcile` asks the gateway later (PAY-02).
 */
#[Api(since: '0.1')]
final class PaymentOutcomeUnknown extends CommerzException
{
    public function __construct(public readonly string $payment, public readonly string $gateway, ?Throwable $previous = null)
    {
        parent::__construct("The outcome of payment '{$payment}' at gateway '{$gateway}' is unknown; reconcile it.", previous: $previous);
    }

    public function code(): string
    {
        return 'sales.payment_outcome_unknown';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Integration;
    }

    public function context(): array
    {
        return ['payment' => $this->payment, 'gateway' => $this->gateway];
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: SalesErrorCode::PaymentOutcomeUnknown,
            category: PackmanErrorCategory::Unavailable,
            retryable: false,
            details: $this->context(),
        );
    }
}
