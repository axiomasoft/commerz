<?php

declare(strict_types=1);

namespace Commerz\Sales\Payments\Exceptions;

use Commerz\Errors\CommerzException;
use Commerz\Money\Money;
use Commerz\Sales\Errors\Enums\SalesErrorCode;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;

/** Raised when a capture exceeds the remaining authorized amount and the gateway does not advertise overcapture. */
#[Api(since: '0.1')]
final class CaptureExceedsAuthorization extends CommerzException
{
    public function __construct(
        public readonly string $session,
        public readonly Money $requested,
        public readonly Money $remaining,
    ) {
        parent::__construct("Payment session '{$session}' has {$remaining->toDecimal()} {$remaining->currency->code} remaining authorization, not {$requested->toDecimal()}.");
    }

    public function code(): string
    {
        return SalesErrorCode::CaptureExceedsAuthorization->value;
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return [
            'session' => $this->session,
            'requested_minor' => $this->requested->amountMinor,
            'remaining_minor' => $this->remaining->amountMinor,
            'currency' => $this->remaining->currency->code,
        ];
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: SalesErrorCode::CaptureExceedsAuthorization,
            category: ErrorCategory::Conflict,
            retryable: false,
            details: $this->descriptorDetails(),
        );
    }
}
