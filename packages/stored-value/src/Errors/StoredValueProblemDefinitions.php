<?php

declare(strict_types=1);

namespace Commerz\StoredValue\Errors;

use Commerz\StoredValue\Errors\Enums\StoredValueErrorCode;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Contracts\ProblemDefinitionProvider;
use Packman\Errors\Data\ProblemDefinition;
use Packman\Errors\Enums\ErrorCategory;

/**
 * Registers RFC 9457 Problem Details definitions for all `axiomasoft/commerz-stored-value` error codes.
 */
#[Api(since: '0.1')]
final readonly class StoredValueProblemDefinitions implements ProblemDefinitionProvider
{
    public function __construct(
        private string $urnPrefix = 'urn:packman:problem:',
    ) {}

    /** @return list<ProblemDefinition> */
    #[Override]
    public function definitions(): array
    {
        return [
            $this->define(StoredValueErrorCode::AccountExpired, 409, 'Stored Value Account Expired', ErrorCategory::Conflict, ['account', 'reason']),
            $this->define(StoredValueErrorCode::AccountFrozen, 409, 'Stored Value Account Frozen', ErrorCategory::Conflict, ['account', 'reason']),
            $this->define(StoredValueErrorCode::AccountNotFound, 404, 'Stored Value Account Not Found', ErrorCategory::NotFound, ['entity', 'id']),
            $this->define(StoredValueErrorCode::AccountVoided, 409, 'Stored Value Account Voided', ErrorCategory::Conflict, ['account', 'reason']),
            $this->define(StoredValueErrorCode::CurrencyMismatch, 422, 'Stored Value Currency Mismatch', ErrorCategory::Validation, ['*']),
            $this->define(StoredValueErrorCode::DuplicateCode, 409, 'Duplicate Gift Card Code', ErrorCategory::Conflict, ['code_last4']),
            $this->define(StoredValueErrorCode::HoldClosed, 409, 'Stored Value Hold Closed', ErrorCategory::Conflict, ['account', 'reason']),
            $this->define(StoredValueErrorCode::HoldNotFound, 404, 'Stored Value Hold Not Found', ErrorCategory::NotFound, ['entity', 'id']),
            $this->define(StoredValueErrorCode::InsufficientBalance, 409, 'Insufficient Stored Value Balance', ErrorCategory::Conflict, ['account', 'requested_minor', 'available_minor', 'currency']),
            $this->define(StoredValueErrorCode::InvalidAmount, 422, 'Invalid Stored Value Amount', ErrorCategory::Validation, ['*']),
            $this->define(StoredValueErrorCode::InvalidCode, 422, 'Invalid Gift Card Code', ErrorCategory::Validation, ['*']),
            $this->define(StoredValueErrorCode::InvalidIdempotencyKey, 422, 'Invalid Idempotency Key', ErrorCategory::Validation, ['*']),
            $this->define(StoredValueErrorCode::InvalidReference, 422, 'Invalid Stored Value Reference', ErrorCategory::Validation, ['*']),
            $this->define(StoredValueErrorCode::InvalidStatusTransition, 409, 'Invalid Stored Value Status Transition', ErrorCategory::Conflict, ['account', 'reason']),
        ];
    }

    /** @param list<string> $detailKeys */
    private function define(
        StoredValueErrorCode $code,
        int $defaultStatus,
        string $title,
        ErrorCategory $category,
        array $detailKeys = [],
    ): ProblemDefinition {
        return new ProblemDefinition(
            code: $code,
            type: $this->urnPrefix.$code->value(),
            defaultStatus: $defaultStatus,
            title: $title,
            retryable: false,
            category: $category,
            detailKeys: $detailKeys,
        );
    }
}
