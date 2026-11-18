<?php

declare(strict_types=1);

namespace Commerz\Errors;

use Commerz\Errors\Enums\CommerzErrorCode;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;

/** A command, query or value object was built with a malformed field. Subclasses narrow the code. */
#[Api(since: '0.1')]
class InvalidCommand extends CommerzException
{
    private const array CODE_MAP = [
        'common.invalid_command' => CommerzErrorCode::InvalidCommand,
        'common.invalid_page_size' => CommerzErrorCode::InvalidPageSize,
        'identity.invalid_id' => CommerzErrorCode::InvalidId,
        'identity.invalid_idempotency_key' => CommerzErrorCode::InvalidIdempotencyKey,
        'identity.invalid_ref' => CommerzErrorCode::InvalidRef,
        'money.currency_mismatch' => CommerzErrorCode::CurrencyMismatch,
        'money.invalid_amount' => CommerzErrorCode::InvalidAmount,
        'money.invalid_currency' => CommerzErrorCode::InvalidCurrency,
        'money.invalid_rate' => CommerzErrorCode::InvalidRate,
        'money.invalid_weights' => CommerzErrorCode::InvalidWeights,
        'money.overflow' => CommerzErrorCode::AmountOverflow,
        'outbox.invalid_event' => CommerzErrorCode::InvalidOutboxEvent,
        'quotes.currency_mismatch' => CommerzErrorCode::QuoteCurrencyMismatch,
        'stores.invalid_currencies' => CommerzErrorCode::InvalidCurrencies,
        'stores.invalid_locale' => CommerzErrorCode::InvalidLocale,
    ];

    public function __construct(
        public readonly string $field,
        public readonly string $reason,
        private readonly string $errorCode = 'common.invalid_command',
    ) {
        parent::__construct("Invalid {$field}: {$reason}.");
    }

    public function code(): string
    {
        return $this->errorCode;
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Validation;
    }

    public function context(): array
    {
        return ['field' => $this->field, 'reason' => $this->reason];
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        $code = self::CODE_MAP[$this->errorCode] ?? CommerzErrorCode::InvalidCommand;
        $details = $this->context();

        if (! isset(self::CODE_MAP[$this->errorCode])) {
            $details['legacy_code'] = $this->errorCode;
        }

        return new ErrorDescriptor(
            code: $code,
            category: ErrorCategory::Validation,
            retryable: false,
            details: self::sanitizeDetails($details),
        );
    }
}
