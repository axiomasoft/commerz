<?php

declare(strict_types=1);

namespace Commerz\Ports\StoredValue\Exceptions;

use Commerz\Errors\CommerzException;
use Commerz\Errors\Enums\CommerzErrorCode;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;

/** A hold or debit would violate INV-SV-001 (`balance_minor - held_minor >= 0`). */
#[Api(since: '0.1')]
final class InsufficientStoredValue extends CommerzException
{
    public function __construct(
        public readonly string $accountId,
        public readonly int $requestedMinor,
        public readonly int $availableMinor,
        public readonly string $currency,
    ) {
        parent::__construct(
            "Insufficient stored value on account '{$accountId}': requested {$requestedMinor} {$currency}, available {$availableMinor} {$currency}.",
        );
    }

    public function code(): string
    {
        return 'stored_value.insufficient_balance';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return [
            'account' => $this->accountId,
            'requested_minor' => $this->requestedMinor,
            'available_minor' => $this->availableMinor,
            'currency' => $this->currency,
        ];
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: CommerzErrorCode::InsufficientStoredValue,
            category: ErrorCategory::Conflict,
            retryable: false,
            details: $this->descriptorDetails(),
        );
    }
}
