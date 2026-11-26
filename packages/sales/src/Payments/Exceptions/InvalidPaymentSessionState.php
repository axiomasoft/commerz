<?php

declare(strict_types=1);

namespace Commerz\Sales\Payments\Exceptions;

use Commerz\Errors\CommerzException;
use Commerz\Sales\Errors\Enums\SalesErrorCode;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;

/** Raised when a capture, void, incremental authorization, or cancel is invalid for the current session/collection state. */
#[Api(since: '0.1')]
final class InvalidPaymentSessionState extends CommerzException
{
    public function __construct(
        public readonly string $entityId,
        public readonly string $status,
        public readonly string $reason,
    ) {
        parent::__construct("Payment entity '{$entityId}' in status '{$status}' cannot perform operation: {$reason}");
    }

    public function code(): string
    {
        return SalesErrorCode::InvalidPaymentSessionState->value;
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return [
            'entity_id' => $this->entityId,
            'status' => $this->status,
            'reason' => $this->reason,
        ];
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: SalesErrorCode::InvalidPaymentSessionState,
            category: ErrorCategory::Conflict,
            retryable: false,
            details: $this->descriptorDetails(),
        );
    }
}
