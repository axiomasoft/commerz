<?php

declare(strict_types=1);

namespace Commerz\Promotions\Errors;

use Commerz\Promotions\Errors\Enums\PromotionsErrorCode;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Contracts\ProblemDefinitionProvider;
use Packman\Errors\Data\ProblemDefinition;
use Packman\Errors\Enums\ErrorCategory;

/**
 * Registers RFC 9457 Problem Details definitions for all `axiomasoft/commerz-promotions` error codes.
 */
#[Api(since: '0.1')]
final readonly class PromotionsProblemDefinitions implements ProblemDefinitionProvider
{
    public function __construct(
        private string $urnPrefix = 'urn:packman:problem:',
    ) {}

    /** @return list<ProblemDefinition> */
    #[Override]
    public function definitions(): array
    {
        return [
            $this->define(PromotionsErrorCode::CodeSpaceExhausted, 409, 'Coupon Code Space Exhausted', ErrorCategory::Conflict, ['pattern', 'missing']),
            $this->define(PromotionsErrorCode::CouponNotFound, 404, 'Coupon Not Found', ErrorCategory::NotFound, ['entity', 'id']),
            $this->define(PromotionsErrorCode::CurrencyMismatch, 422, 'Promotion Currency Mismatch', ErrorCategory::Validation, ['*']),
            $this->define(PromotionsErrorCode::DuplicateCode, 409, 'Duplicate Promotion Code', ErrorCategory::Conflict, ['entity', 'code']),
            $this->define(PromotionsErrorCode::InvalidActivation, 422, 'Invalid Promotion Activation', ErrorCategory::Validation, ['*']),
            $this->define(PromotionsErrorCode::InvalidBudget, 422, 'Invalid Promotion Budget', ErrorCategory::Validation, ['*']),
            $this->define(PromotionsErrorCode::InvalidChannels, 422, 'Invalid Promotion Channels', ErrorCategory::Validation, ['*']),
            $this->define(PromotionsErrorCode::InvalidCode, 422, 'Invalid Promotion Code', ErrorCategory::Validation, ['*']),
            $this->define(PromotionsErrorCode::InvalidConditionOutcome, 422, 'Invalid Condition Outcome', ErrorCategory::Validation, ['*']),
            $this->define(PromotionsErrorCode::InvalidCount, 422, 'Invalid Coupon Count', ErrorCategory::Validation, ['*']),
            $this->define(PromotionsErrorCode::InvalidCouponCode, 422, 'Invalid Coupon Code', ErrorCategory::Validation, ['*']),
            $this->define(PromotionsErrorCode::InvalidDiscount, 422, 'Invalid Promotion Discount', ErrorCategory::Validation, ['*']),
            $this->define(PromotionsErrorCode::InvalidGroup, 422, 'Invalid Customer Group', ErrorCategory::Validation, ['*']),
            $this->define(PromotionsErrorCode::InvalidLimits, 422, 'Invalid Usage Limits', ErrorCategory::Validation, ['*']),
            $this->define(PromotionsErrorCode::InvalidName, 422, 'Invalid Promotion Name', ErrorCategory::Validation, ['*']),
            $this->define(PromotionsErrorCode::InvalidPattern, 422, 'Invalid Coupon Pattern', ErrorCategory::Validation, ['*']),
            $this->define(PromotionsErrorCode::InvalidPriority, 422, 'Invalid Promotion Priority', ErrorCategory::Validation, ['*']),
            $this->define(PromotionsErrorCode::InvalidRule, 422, 'Invalid Promotion Rule', ErrorCategory::Validation, ['*']),
            $this->define(PromotionsErrorCode::InvalidRuleConfig, 422, 'Invalid Promotion Rule Config', ErrorCategory::Validation, ['*']),
            $this->define(PromotionsErrorCode::InvalidRuleResult, 503, 'Invalid Promotion Rule Result', ErrorCategory::Unavailable, ['rule', 'reason']),
            $this->define(PromotionsErrorCode::InvalidRules, 422, 'Invalid Promotion Rules', ErrorCategory::Validation, ['*']),
            $this->define(PromotionsErrorCode::InvalidStatus, 422, 'Invalid Promotion Status', ErrorCategory::Validation, ['*']),
            $this->define(PromotionsErrorCode::InvalidUnitPool, 422, 'Invalid Unit Pool', ErrorCategory::Validation, ['*']),
            $this->define(PromotionsErrorCode::InvalidUpdate, 422, 'Invalid Promotion Update', ErrorCategory::Validation, ['*']),
            $this->define(PromotionsErrorCode::InvalidUsage, 422, 'Invalid Promotion Usage', ErrorCategory::Validation, ['*']),
            $this->define(PromotionsErrorCode::InvalidVerdict, 422, 'Invalid Promotion Verdict', ErrorCategory::Validation, ['*']),
            $this->define(PromotionsErrorCode::InvalidWindow, 422, 'Invalid Promotion Effective Window', ErrorCategory::Validation, ['*']),
            $this->define(PromotionsErrorCode::NoChannels, 422, 'Promotion Requires Channels', ErrorCategory::Validation, ['*']),
            $this->define(PromotionsErrorCode::NotACouponPromotion, 409, 'Not A Coupon Promotion', ErrorCategory::Conflict, ['promotion', 'activator']),
            $this->define(PromotionsErrorCode::PatternTooSmall, 422, 'Coupon Pattern Too Small', ErrorCategory::Validation, ['*']),
            $this->define(PromotionsErrorCode::PromotionArchived, 409, 'Promotion Archived', ErrorCategory::Conflict, ['promotion']),
            $this->define(PromotionsErrorCode::PromotionNotFound, 404, 'Promotion Not Found', ErrorCategory::NotFound, ['entity', 'id']),
            $this->define(PromotionsErrorCode::PromotionUnavailable, 409, 'Promotion Unavailable', ErrorCategory::Conflict, ['promotion', 'coupon', 'reason']),
            $this->define(PromotionsErrorCode::RuleNotAllowedInStage, 422, 'Rule Not Allowed In Stage', ErrorCategory::Validation, ['*']),
            $this->define(PromotionsErrorCode::UnknownActivator, 422, 'Unknown Promotion Activator', ErrorCategory::Validation, ['*']),
            $this->define(PromotionsErrorCode::UnknownRule, 422, 'Unknown Promotion Rule', ErrorCategory::Validation, ['*']),
        ];
    }

    /** @param list<string> $detailKeys */
    private function define(
        PromotionsErrorCode $code,
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
