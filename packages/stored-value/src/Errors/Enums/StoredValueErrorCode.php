<?php

declare(strict_types=1);

namespace Commerz\StoredValue\Errors\Enums;

use Override;
use Packman\Attributes\Api;
use Packman\Errors\Contracts\ErrorCode;

/**
 * Stable wire-level error codes emitted by `axiomasoft/commerz-stored-value`.
 */
#[Api(since: '0.1')]
enum StoredValueErrorCode: string implements ErrorCode
{
    case AccountExpired = 'stored-value.account_expired';
    case AccountFrozen = 'stored-value.account_frozen';
    case AccountNotFound = 'stored-value.account_not_found';
    case AccountVoided = 'stored-value.account_voided';
    case CurrencyMismatch = 'stored-value.currency_mismatch';
    case DuplicateCode = 'stored-value.duplicate_code';
    case HoldClosed = 'stored-value.hold_closed';
    case HoldNotFound = 'stored-value.hold_not_found';
    case InsufficientBalance = 'stored-value.insufficient_balance';
    case InvalidAmount = 'stored-value.invalid_amount';
    case InvalidCode = 'stored-value.invalid_code';
    case InvalidIdempotencyKey = 'stored-value.invalid_idempotency_key';
    case InvalidReference = 'stored-value.invalid_reference';
    case InvalidStatusTransition = 'stored-value.invalid_status_transition';

    #[Override]
    public function value(): string
    {
        return $this->value;
    }
}
