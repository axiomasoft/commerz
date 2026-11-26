<?php

declare(strict_types=1);

namespace Commerz\Errors;

use Commerz\Errors\Enums\CommerzErrorCode;
use Packman\Attributes\Api;
use Packman\Errors\Contracts\ProblemDefinitionProvider;
use Packman\Errors\Data\ProblemDefinition;
use Packman\Errors\Enums\ErrorCategory;

/**
 * Registers RFC 9457 Problem Details definitions for all `axiomasoft/commerz` core error codes.
 */
#[Api(since: '0.1')]
final readonly class CommerzProblemDefinitions implements ProblemDefinitionProvider
{
    public function __construct(
        private string $urnPrefix = 'urn:packman:problem:',
    ) {}

    /** @return list<ProblemDefinition> */
    public function definitions(): array
    {
        return [
            $this->define(CommerzErrorCode::InvalidCommand, 422, 'Invalid Command', ErrorCategory::Validation, ['*']),
            $this->define(CommerzErrorCode::NotFound, 404, 'Entity Not Found', ErrorCategory::NotFound, ['*']),
            $this->define(CommerzErrorCode::IdempotencyConflict, 409, 'Idempotency Key Conflict', ErrorCategory::Conflict, ['operation', 'key']),
            $this->define(CommerzErrorCode::InvalidConfiguration, 422, 'Invalid Commerz Configuration', ErrorCategory::Validation, ['*']),
            $this->define(CommerzErrorCode::UnsupportedContribution, 422, 'Unsupported Plugin Contribution', ErrorCategory::Unsupported, ['kind', 'key', 'reason']),
            $this->define(CommerzErrorCode::CurrencyMismatch, 422, 'Currency Mismatch', ErrorCategory::Validation, ['field', 'reason', 'left', 'right']),
            $this->define(CommerzErrorCode::InvalidCurrency, 422, 'Invalid Currency Code', ErrorCategory::Validation, ['field', 'reason', 'given']),
            $this->define(CommerzErrorCode::InvalidAmount, 422, 'Invalid Monetary Amount', ErrorCategory::Validation, ['*']),
            $this->define(CommerzErrorCode::AmountOverflow, 422, 'Monetary Amount Overflow', ErrorCategory::Validation, ['*']),
            $this->define(CommerzErrorCode::InvalidRate, 422, 'Invalid Rate', ErrorCategory::Validation, ['*']),
            $this->define(CommerzErrorCode::InvalidWeights, 422, 'Invalid Allocation Weights', ErrorCategory::Validation, ['*']),
            $this->define(CommerzErrorCode::InvalidId, 422, 'Invalid Identifier', ErrorCategory::Validation, ['*']),
            $this->define(CommerzErrorCode::InvalidRef, 422, 'Invalid Reference', ErrorCategory::Validation, ['*']),
            $this->define(CommerzErrorCode::InvalidIdempotencyKey, 422, 'Invalid Idempotency Key', ErrorCategory::Validation, ['*']),
            $this->define(CommerzErrorCode::InvalidPageSize, 422, 'Invalid Page Size', ErrorCategory::Validation, ['*']),
            $this->define(CommerzErrorCode::InvalidCurrencies, 422, 'Invalid Currency List', ErrorCategory::Validation, ['*']),
            $this->define(CommerzErrorCode::InvalidLocale, 422, 'Invalid Locale Configuration', ErrorCategory::Validation, ['*']),
            $this->define(CommerzErrorCode::InvalidOutboxEvent, 422, 'Invalid Outbox Event', ErrorCategory::Validation, ['*']),
            $this->define(CommerzErrorCode::QuoteCurrencyMismatch, 422, 'Quote Currency Mismatch', ErrorCategory::Validation, ['*']),
            $this->define(CommerzErrorCode::QuoteFailed, 409, 'Quote Computation Failed', ErrorCategory::Conflict, ['stage', 'reason', 'cause']),
            $this->define(CommerzErrorCode::ChannelArchived, 409, 'Channel Archived', ErrorCategory::Conflict, ['channel']),
            $this->define(CommerzErrorCode::ChannelNotFound, 404, 'Channel Not Found', ErrorCategory::NotFound, ['entity', 'id']),
            $this->define(CommerzErrorCode::CurrencyNotAllowed, 422, 'Currency Not Allowed in Channel', ErrorCategory::Validation, ['channel', 'currency']),
            $this->define(CommerzErrorCode::DuplicateCode, 409, 'Duplicate Store or Channel Code', ErrorCategory::Conflict, ['entity', 'code']),
            $this->define(CommerzErrorCode::StoreNotFound, 404, 'Store Not Found', ErrorCategory::NotFound, ['entity', 'id']),
            $this->define(CommerzErrorCode::PriceNotFound, 404, 'Price Not Found', ErrorCategory::NotFound, ['entity', 'id']),
            $this->define(CommerzErrorCode::UsageLimitReached, 429, 'Promotion Usage Limit Reached', ErrorCategory::RateLimit, ['promotion', 'coupon', 'limit']),
            $this->define(CommerzErrorCode::SellableUnavailable, 404, 'Sellable Unavailable', ErrorCategory::NotFound, ['sellable', 'reason']),
            $this->define(CommerzErrorCode::InsufficientStock, 409, 'Insufficient Stock', ErrorCategory::Conflict, ['sellable', 'requested', 'available']),
            $this->define(CommerzErrorCode::InsufficientStoredValue, 409, 'Insufficient Stored Value', ErrorCategory::Conflict, ['account', 'requested_minor', 'available_minor', 'currency']),
            $this->define(CommerzErrorCode::ReservationNotFound, 404, 'Stock Reservation Not Found', ErrorCategory::NotFound, ['entity', 'id']),
            $this->define(CommerzErrorCode::StoredValueNotFound, 404, 'Stored Value Not Found', ErrorCategory::NotFound, ['entity', 'id']),
            $this->define(CommerzErrorCode::StoredValueUnavailable, 409, 'Stored Value Unavailable', ErrorCategory::Conflict, ['account', 'reason']),
            $this->define(CommerzErrorCode::DuplicateWebhookSubscription, 409, 'Duplicate Webhook Subscription Code', ErrorCategory::Conflict, ['code']),
            $this->define(CommerzErrorCode::InvalidWebhookSignature, 401, 'Invalid Webhook Signature', ErrorCategory::Authentication, ['source', 'reason']),
            $this->define(CommerzErrorCode::WebhookDeliveryNotFound, 404, 'Webhook Delivery Not Found', ErrorCategory::NotFound, ['entity', 'id']),
            $this->define(CommerzErrorCode::WebhookSubscriptionNotFound, 404, 'Webhook Subscription Not Found', ErrorCategory::NotFound, ['entity', 'id']),
            $this->define(CommerzErrorCode::WebhookTransportFailed, 503, 'Webhook Transport Failed', ErrorCategory::Unavailable, ['url', 'reason', 'status_code'], retryable: true),
        ];
    }

    /** @param list<string> $detailKeys */
    private function define(
        CommerzErrorCode $code,
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
