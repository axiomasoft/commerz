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

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: SalesErrorCode::PaymentInProgress,
            category: PackmanErrorCategory::Conflict,
            retryable: false,
            details: $this->context(),
        );
    }
}
