<?php

declare(strict_types=1);

namespace Commerz\Subscriptions\Errors\Exceptions;

use Commerz\Errors\CommerzException;
use Commerz\Subscriptions\Errors\Enums\SubscriptionsErrorCode;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;
use Throwable;

/**
 * Coded domain failure raised by `axiomasoft/commerz-subscriptions`.
 */
#[Api(since: '0.1')]
class SubscriptionException extends CommerzException
{
    /**
     * @param  array<string, scalar|null>  $details
     */
    public function __construct(
        public readonly SubscriptionsErrorCode $errorCode,
        string $message,
        private readonly ErrorCategory $errorCategory,
        private readonly string $pointer = '',
        private readonly array $details = [],
        private readonly bool $isRetryable = false,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    #[Override]
    public function code(): string
    {
        return $this->errorCode->value();
    }

    /** @return array<string, scalar|null> */
    #[Override]
    public function context(): array
    {
        return $this->details;
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: $this->errorCode,
            category: $this->errorCategory,
            retryable: $this->isRetryable,
            path: $this->pointer === '' ? null : $this->pointer,
            details: $this->descriptorDetails(),
        );
    }

    public static function contractNotFound(string $id): self
    {
        return new self(
            SubscriptionsErrorCode::ContractNotFound,
            "Subscription contract '{$id}' was not found.",
            ErrorCategory::NotFound,
            '/id',
            ['entity' => 'subscription_contract', 'id' => $id],
        );
    }

    public static function cycleNotFound(string $id): self
    {
        return new self(
            SubscriptionsErrorCode::CycleNotFound,
            "Billing cycle '{$id}' was not found.",
            ErrorCategory::NotFound,
            '/cycleId',
            ['entity' => 'billing_cycle', 'id' => $id],
        );
    }

    public static function invalidTransition(string $contractId, string $from, string $to, string $reason): self
    {
        return new self(
            $from === 'canceled' ? SubscriptionsErrorCode::ContractCanceled : SubscriptionsErrorCode::InvalidStatusTransition,
            "Cannot transition subscription '{$contractId}' from '{$from}' to '{$to}': {$reason}",
            ErrorCategory::Conflict,
            '/status',
            ['contract_id' => $contractId, 'from' => $from, 'to' => $to, 'reason' => $reason],
        );
    }

    public static function cycleAlreadyProcessed(string $cycleId, string $status): self
    {
        return new self(
            SubscriptionsErrorCode::CycleAlreadyProcessed,
            "Billing cycle '{$cycleId}' is already in status '{$status}'.",
            ErrorCategory::Conflict,
            '/cycleId',
            ['cycle_id' => $cycleId, 'status' => $status],
        );
    }

    public static function missingPaymentMethod(string $contractId): self
    {
        return new self(
            SubscriptionsErrorCode::MissingPaymentMethod,
            "Subscription contract '{$contractId}' requires a saved payment method.",
            ErrorCategory::Validation,
            '/paymentMethodRef',
            ['contract_id' => $contractId, 'reason' => 'saved_payment_method_required'],
        );
    }
}
