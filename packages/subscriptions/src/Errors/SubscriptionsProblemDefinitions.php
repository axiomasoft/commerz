<?php

declare(strict_types=1);

namespace Commerz\Subscriptions\Errors;

use Commerz\Subscriptions\Errors\Enums\SubscriptionsErrorCode;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Contracts\ProblemDefinitionProvider;
use Packman\Errors\Data\ProblemDefinition;
use Packman\Errors\Enums\ErrorCategory;

/**
 * Registers RFC 9457 Problem Details definitions for all `axiomasoft/commerz-subscriptions` error codes.
 */
#[Api(since: '0.1')]
final readonly class SubscriptionsProblemDefinitions implements ProblemDefinitionProvider
{
    public function __construct(
        private string $urnPrefix = 'urn:packman:problem:',
    ) {}

    /** @return list<ProblemDefinition> */
    #[Override]
    public function definitions(): array
    {
        return [
            $this->define(SubscriptionsErrorCode::ContractCanceled, 409, 'Subscription Contract Canceled', ErrorCategory::Conflict, ['contract_id', 'reason']),
            $this->define(SubscriptionsErrorCode::ContractNotFound, 404, 'Subscription Contract Not Found', ErrorCategory::NotFound, ['entity', 'id']),
            $this->define(SubscriptionsErrorCode::CurrencyMismatch, 422, 'Subscription Currency Mismatch', ErrorCategory::Validation, ['*']),
            $this->define(SubscriptionsErrorCode::CycleAlreadyProcessed, 409, 'Billing Cycle Already Processed', ErrorCategory::Conflict, ['cycle_id', 'status']),
            $this->define(SubscriptionsErrorCode::CycleNotFound, 404, 'Billing Cycle Not Found', ErrorCategory::NotFound, ['entity', 'id']),
            $this->define(SubscriptionsErrorCode::IdempotencyConflict, 409, 'Subscription Idempotency Conflict', ErrorCategory::Conflict, ['key', 'reason']),
            $this->define(SubscriptionsErrorCode::InvalidCommand, 422, 'Invalid Subscription Command', ErrorCategory::Validation, ['*']),
            $this->define(SubscriptionsErrorCode::InvalidRetryPolicy, 422, 'Invalid Subscription Retry Policy', ErrorCategory::Validation, ['*']),
            $this->define(SubscriptionsErrorCode::InvalidSellingPlan, 422, 'Invalid Subscription Selling Plan', ErrorCategory::Validation, ['*']),
            $this->define(SubscriptionsErrorCode::InvalidStatusTransition, 409, 'Invalid Subscription Status Transition', ErrorCategory::Conflict, ['contract_id', 'from', 'to', 'reason']),
            $this->define(SubscriptionsErrorCode::MissingPaymentMethod, 422, 'Saved Payment Method Required', ErrorCategory::Validation, ['contract_id', 'reason']),
            $this->define(SubscriptionsErrorCode::RenewalOrderNotFound, 404, 'Subscription Renewal Order Not Found', ErrorCategory::NotFound, ['entity', 'id']),
            $this->define(SubscriptionsErrorCode::RenewalPaymentFailed, 503, 'Subscription Renewal Payment Failed', ErrorCategory::Unavailable, ['contract_id', 'cycle_id', 'reason'], retryable: true),
        ];
    }

    /** @param list<string> $detailKeys */
    private function define(
        SubscriptionsErrorCode $code,
        int $defaultStatus,
        string $title,
        ErrorCategory $category,
        array $detailKeys = [],
        bool $retryable = false,
    ): ProblemDefinition {
        return new ProblemDefinition(
            code: $code,
            type: $this->urnPrefix.$code->value(),
            defaultStatus: $defaultStatus,
            title: $title,
            retryable: $retryable,
            category: $category,
            detailKeys: $detailKeys,
        );
    }
}
