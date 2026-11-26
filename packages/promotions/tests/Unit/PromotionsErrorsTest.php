<?php

declare(strict_types=1);

use Commerz\Errors\CommerzException;
use Commerz\Promotions\Campaigns\Exceptions\CampaignArchived;
use Commerz\Promotions\Campaigns\Exceptions\CampaignBudgetExceeded;
use Commerz\Promotions\Campaigns\Exceptions\CampaignNotFound;
use Commerz\Promotions\Common\Exceptions\DuplicateCode;
use Commerz\Promotions\Coupons\Exceptions\CodeSpaceExhausted;
use Commerz\Promotions\Coupons\Exceptions\CouponNotFound;
use Commerz\Promotions\Coupons\Exceptions\NotACouponPromotion;
use Commerz\Promotions\Definitions\Exceptions\PromotionArchived;
use Commerz\Promotions\Definitions\Exceptions\PromotionNotFound;
use Commerz\Promotions\Errors\Enums\PromotionsErrorCode;
use Commerz\Promotions\Errors\PromotionsProblemDefinitions;
use Commerz\Promotions\Rules\Exceptions\InvalidRuleConfig;
use Commerz\Promotions\Rules\Exceptions\InvalidRuleResult;
use Commerz\Promotions\Usage\Exceptions\CouponHoldDisabled;
use Commerz\Promotions\Usage\Exceptions\CouponHoldNotFound;
use Commerz\Promotions\Usage\Exceptions\CouponHoldUnavailable;
use Commerz\Promotions\Usage\Exceptions\PromotionUnavailable;
use Packman\Errors\Data\ProblemDefinition;
use Packman\Errors\Enums\ErrorCategory;
use Packman\Errors\Exceptions\CodedException;

it('covers every PromotionsErrorCode case with a matching ProblemDefinition', function (): void {
    $definitions = (new PromotionsProblemDefinitions)->definitions();
    $codes = array_map(static fn (ProblemDefinition $d): string => $d->code->value(), $definitions);
    $expected = array_column(PromotionsErrorCode::cases(), 'value');

    sort($codes);
    sort($expected);

    expect($codes)->toBe($expected);
});

it('exposes Packman error descriptors on promotions exceptions', function (CommerzException $e, PromotionsErrorCode $code, ErrorCategory $category): void {
    $descriptor = $e->errorDescriptor();

    expect($e)->toBeInstanceOf(CodedException::class)
        ->and($descriptor->errorCode)->toBe($code)
        ->and($descriptor->category)->toBe($category);
})->with([
    'campaign archived' => [new CampaignArchived('winter'), PromotionsErrorCode::CampaignArchived, ErrorCategory::Conflict],
    'campaign budget exceeded' => [new CampaignBudgetExceeded('camp1', CampaignBudgetExceeded::SPEND_CAP), PromotionsErrorCode::CampaignBudgetExceeded, ErrorCategory::RateLimit],
    'campaign not found' => [new CampaignNotFound('camp1'), PromotionsErrorCode::CampaignNotFound, ErrorCategory::NotFound],
    'coupon hold disabled' => [new CouponHoldDisabled, PromotionsErrorCode::CouponHoldDisabled, ErrorCategory::Conflict],
    'coupon hold not found' => [new CouponHoldNotFound('hold1'), PromotionsErrorCode::CouponHoldNotFound, ErrorCategory::NotFound],
    'coupon hold unavailable' => [new CouponHoldUnavailable('hold1', CouponHoldUnavailable::HOLD_EXPIRED), PromotionsErrorCode::CouponHoldUnavailable, ErrorCategory::Conflict],
    'duplicate code' => [new DuplicateCode('Promotion', 'summer'), PromotionsErrorCode::DuplicateCode, ErrorCategory::Conflict],
    'code space exhausted' => [new CodeSpaceExhausted('PROMO-####', 10), PromotionsErrorCode::CodeSpaceExhausted, ErrorCategory::Conflict],
    'coupon not found' => [new CouponNotFound('cp1'), PromotionsErrorCode::CouponNotFound, ErrorCategory::NotFound],
    'not a coupon promotion' => [new NotACouponPromotion('summer', 'automatic'), PromotionsErrorCode::NotACouponPromotion, ErrorCategory::Conflict],
    'promotion archived' => [new PromotionArchived('summer'), PromotionsErrorCode::PromotionArchived, ErrorCategory::Conflict],
    'promotion not found' => [new PromotionNotFound('pr1'), PromotionsErrorCode::PromotionNotFound, ErrorCategory::NotFound],
    'invalid rule config' => [new InvalidRuleConfig('min', 'must be positive'), PromotionsErrorCode::InvalidRuleConfig, ErrorCategory::Validation],
    'invalid rule result' => [new InvalidRuleResult('cart.subtotal_at_least', 'bad'), PromotionsErrorCode::InvalidRuleResult, ErrorCategory::Unavailable],
    'promotion unavailable' => [new PromotionUnavailable('pr1', null, PromotionUnavailable::PROMOTION_NOT_ACTIVE), PromotionsErrorCode::PromotionUnavailable, ErrorCategory::Conflict],
]);
