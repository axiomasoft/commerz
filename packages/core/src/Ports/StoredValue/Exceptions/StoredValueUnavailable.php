<?php

declare(strict_types=1);

namespace Commerz\Ports\StoredValue\Exceptions;

use Commerz\Errors\CommerzException;
use Commerz\Errors\Enums\CommerzErrorCode;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;

/** A stored-value account or hold cannot accept the requested operation (e.g. frozen, expired, voided, closed). */
#[Api(since: '0.1')]
final class StoredValueUnavailable extends CommerzException
{
    public function __construct(
        public readonly string $accountId,
        public readonly string $reason,
        ?string $message = null,
    ) {
        parent::__construct($message ?? "Stored-value account '{$accountId}' is unavailable: {$reason}.");
    }

    public function code(): string
    {
        return 'stored_value.unavailable';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return [
            'account' => $this->accountId,
            'reason' => $this->reason,
        ];
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: CommerzErrorCode::StoredValueUnavailable,
            category: ErrorCategory::Conflict,
            retryable: false,
            details: $this->descriptorDetails(),
        );
    }
}
