<?php

declare(strict_types=1);

namespace Commerz\Errors;

use Commerz\Errors\Enums\CommerzErrorCode;
use Packman\Attributes\Api;
use Packman\Errors\Contracts\ProblemDefinitionProvider;
use Packman\Errors\Data\ProblemDefinition;
use Packman\Errors\Enums\ErrorCategory as PackmanErrorCategory;

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
            $this->define(CommerzErrorCode::InvalidCommand, 422, 'Invalid Command', PackmanErrorCategory::Validation, ['*']),
            $this->define(CommerzErrorCode::NotFound, 404, 'Entity Not Found', PackmanErrorCategory::NotFound, ['*']),
            $this->define(CommerzErrorCode::IdempotencyConflict, 409, 'Idempotency Key Conflict', PackmanErrorCategory::Conflict, ['operation', 'key']),
            $this->define(CommerzErrorCode::InvalidConfiguration, 422, 'Invalid Commerz Configuration', PackmanErrorCategory::Validation, ['*']),
            $this->define(CommerzErrorCode::UnsupportedContribution, 422, 'Unsupported Plugin Contribution', PackmanErrorCategory::Unsupported, ['kind', 'key', 'reason']),
            $this->define(CommerzErrorCode::CurrencyMismatch, 422, 'Currency Mismatch', PackmanErrorCategory::Validation, ['field', 'reason', 'left', 'right']),
            $this->define(CommerzErrorCode::InvalidCurrency, 422, 'Invalid Currency Code', PackmanErrorCategory::Validation, ['field', 'reason', 'given']),
            $this->define(CommerzErrorCode::InvalidAmount, 422, 'Invalid Monetary Amount', PackmanErrorCategory::Validation, ['*']),
            $this->define(CommerzErrorCode::AmountOverflow, 422, 'Monetary Amount Overflow', PackmanErrorCategory::Validation, ['*']),
            $this->define(CommerzErrorCode::InvalidRate, 422, 'Invalid Rate', PackmanErrorCategory::Validation, ['*']),
            $this->define(CommerzErrorCode::InvalidWeights, 422, 'Invalid Allocation Weights', PackmanErrorCategory::Validation, ['*']),
            $this->define(CommerzErrorCode::InvalidId, 422, 'Invalid Identifier', PackmanErrorCategory::Validation, ['*']),
            $this->define(CommerzErrorCode::InvalidRef, 422, 'Invalid Reference', PackmanErrorCategory::Validation, ['*']),
            $this->define(CommerzErrorCode::InvalidIdempotencyKey, 422, 'Invalid Idempotency Key', PackmanErrorCategory::Validation, ['*']),
            $this->define(CommerzErrorCode::InvalidPageSize, 422, 'Invalid Page Size', PackmanErrorCategory::Validation, ['*']),
            $this->define(CommerzErrorCode::InvalidCurrencies, 422, 'Invalid Currency List', PackmanErrorCategory::Validation, ['*']),
            $this->define(CommerzErrorCode::InvalidLocale, 422, 'Invalid Locale Configuration', PackmanErrorCategory::Validation, ['*']),
            $this->define(CommerzErrorCode::InvalidOutboxEvent, 422, 'Invalid Outbox Event', PackmanErrorCategory::Validation, ['*']),
            $this->define(CommerzErrorCode::QuoteCurrencyMismatch, 422, 'Quote Currency Mismatch', PackmanErrorCategory::Validation, ['*']),
            $this->define(CommerzErrorCode::QuoteFailed, 409, 'Quote Computation Failed', PackmanErrorCategory::Conflict, ['stage', 'reason', 'cause']),
            $this->define(CommerzErrorCode::ChannelArchived, 409, 'Channel Archived', PackmanErrorCategory::Conflict, ['channel']),
            $this->define(CommerzErrorCode::ChannelNotFound, 404, 'Channel Not Found', PackmanErrorCategory::NotFound, ['entity', 'id']),
            $this->define(CommerzErrorCode::CurrencyNotAllowed, 422, 'Currency Not Allowed in Channel', PackmanErrorCategory::Validation, ['channel', 'currency']),
            $this->define(CommerzErrorCode::DuplicateCode, 409, 'Duplicate Store or Channel Code', PackmanErrorCategory::Conflict, ['entity', 'code']),
            $this->define(CommerzErrorCode::StoreNotFound, 404, 'Store Not Found', PackmanErrorCategory::NotFound, ['entity', 'id']),
            $this->define(CommerzErrorCode::PriceNotFound, 404, 'Price Not Found', PackmanErrorCategory::NotFound, ['entity', 'id']),
            $this->define(CommerzErrorCode::UsageLimitReached, 429, 'Promotion Usage Limit Reached', PackmanErrorCategory::RateLimit, ['promotion', 'coupon', 'limit']),
            $this->define(CommerzErrorCode::SellableUnavailable, 404, 'Sellable Unavailable', PackmanErrorCategory::NotFound, ['sellable', 'reason']),
            $this->define(CommerzErrorCode::InsufficientStock, 409, 'Insufficient Stock', PackmanErrorCategory::Conflict, ['sellable', 'requested', 'available']),
            $this->define(CommerzErrorCode::ReservationNotFound, 404, 'Stock Reservation Not Found', PackmanErrorCategory::NotFound, ['entity', 'id']),
        ];
    }

    /** @param list<string> $detailKeys */
    private function define(
        CommerzErrorCode $code,
        int $defaultStatus,
        string $title,
        PackmanErrorCategory $category,
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
