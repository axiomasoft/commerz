<?php

declare(strict_types=1);

namespace Commerz\Promotions\Errors\Enums;

use Override;
use Packman\Attributes\Api;
use Packman\Errors\Contracts\ErrorCode;

/**
 * Stable wire-level error codes emitted by `axiomasoft/commerz-promotions`.
 */
#[Api(since: '0.1')]
enum PromotionsErrorCode: string implements ErrorCode
{
    case CampaignArchived = 'promotions.campaign_archived';
    case CampaignBudgetExceeded = 'promotions.campaign_budget_exceeded';
    case CampaignNotFound = 'promotions.campaign_not_found';
    case CodeSpaceExhausted = 'promotions.code_space_exhausted';
    case CouponHoldDisabled = 'promotions.coupon_hold_disabled';
    case CouponHoldNotFound = 'promotions.coupon_hold_not_found';
    case CouponHoldUnavailable = 'promotions.coupon_hold_unavailable';
    case CouponNotFound = 'promotions.coupon_not_found';
    case CurrencyMismatch = 'promotions.currency_mismatch';
    case DuplicateCode = 'promotions.duplicate_code';
    case InvalidActivation = 'promotions.invalid_activation';
    case InvalidBudget = 'promotions.invalid_budget';
    case InvalidChannels = 'promotions.invalid_channels';
    case InvalidCode = 'promotions.invalid_code';
    case InvalidConditionOutcome = 'promotions.invalid_condition_outcome';
    case InvalidCount = 'promotions.invalid_count';
    case InvalidCouponCode = 'promotions.invalid_coupon_code';
    case InvalidDiscount = 'promotions.invalid_discount';
    case InvalidGroup = 'promotions.invalid_group';
    case InvalidLimits = 'promotions.invalid_limits';
    case InvalidName = 'promotions.invalid_name';
    case InvalidPattern = 'promotions.invalid_pattern';
    case InvalidPriority = 'promotions.invalid_priority';
    case InvalidRule = 'promotions.invalid_rule';
    case InvalidRuleConfig = 'promotions.invalid_rule_config';
    case InvalidRuleResult = 'promotions.invalid_rule_result';
    case InvalidRules = 'promotions.invalid_rules';
    case InvalidStatus = 'promotions.invalid_status';
    case InvalidUnitPool = 'promotions.invalid_unit_pool';
    case InvalidUpdate = 'promotions.invalid_update';
    case InvalidUsage = 'promotions.invalid_usage';
    case InvalidVerdict = 'promotions.invalid_verdict';
    case InvalidWindow = 'promotions.invalid_window';
    case NoChannels = 'promotions.no_channels';
    case NotACouponPromotion = 'promotions.not_a_coupon_promotion';
    case PatternTooSmall = 'promotions.pattern_too_small';
    case PromotionArchived = 'promotions.promotion_archived';
    case PromotionNotFound = 'promotions.promotion_not_found';
    case PromotionUnavailable = 'promotions.promotion_unavailable';
    case RuleNotAllowedInStage = 'promotions.rule_not_allowed_in_stage';
    case UnknownActivator = 'promotions.unknown_activator';
    case UnknownRule = 'promotions.unknown_rule';

    #[Override]
    public function value(): string
    {
        return $this->value;
    }
}
