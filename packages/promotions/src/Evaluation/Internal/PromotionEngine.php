<?php

declare(strict_types=1);

namespace Commerz\Promotions\Evaluation\Internal;

use Commerz\Money\Money;
use Commerz\Promotions\Campaigns\Data\CampaignBudget;
use Commerz\Promotions\Configuration\PromotionsOptions;
use Commerz\Promotions\Definitions\Data\PromotionVerdict;
use Commerz\Promotions\Definitions\Enums\Stacking;
use Commerz\Promotions\Definitions\Enums\VerdictKind;
use Commerz\Promotions\Rules\Contracts\GiftPromotionEffect;
use Commerz\Promotions\Rules\Contracts\PromotionCondition;
use Commerz\Promotions\Rules\Contracts\PromotionEffect;
use Commerz\Promotions\Rules\Data\ConditionInput;
use Commerz\Promotions\Rules\Data\ConditionOutcome;
use Commerz\Promotions\Rules\Data\EffectInput;
use Commerz\Promotions\Rules\Data\GiftEffectResult;
use Commerz\Promotions\Rules\Data\RuleParams;
use Commerz\Promotions\Rules\Enums\ConditionRole;
use Commerz\Promotions\Rules\Enums\PromotionStage;
use Commerz\Promotions\Rules\Exceptions\InvalidRuleResult;
use Commerz\Promotions\Rules\Internal\LoadedRule;
use Commerz\Quotes\Data\Adjustment;
use Commerz\Quotes\Data\QuoteLine;
use Commerz\Quotes\Data\QuoteState;
use Commerz\Quotes\Data\Rejection;
use LogicException;
use Packman\Attributes\Internal;

/**
 * Runs the promotions of one stage (D15, D26): the switched-on candidates in priority DESC, code ASC order, each
 * applied on top of what the earlier ones left, whole or not at all (PRM-05): its effects work on a scratch state that
 * is dropped on any refusal. An exclusive promotion applies only as the first of its stage and stops the rest, as does
 * one with `stopFurther` once applied. Every candidate gets a verdict in the session; only a promotion switched on by
 * a code leaves a `Rejection` in the quote. Limits and campaign budgets are read from the session snapshot as a hint;
 * they hold atomically when the order is placed.
 */
#[Internal]
final readonly class PromotionEngine
{
    public function __construct(
        private PromotionSessions $sessions,
        private PromotionsOptions $config,
        private DiscountGuard $guard,
    ) {}

    public function run(PromotionStage $stage, QuoteState $state): QuoteState
    {
        $session = $stage === PromotionStage::Catalog ? $this->sessions->start($state->input) : $this->sessions->for($state->input);

        if ($stage === PromotionStage::Catalog) {
            foreach ($session->codeRejections as $rejection) {
                $state = $state->withRejection($rejection);
            }
        }

        $applied = false;
        $stoppedBy = null;

        foreach ($session->candidates($stage) as $candidate) {
            $refusal = match (true) {
                $stoppedBy !== null => [VerdictKind::Skipped, 'stopped_by_promotion', "Promotion '{$stoppedBy}' stops the promotions after it."],
                $candidate->unsupported() !== null => [VerdictKind::Rejected, 'unsupported_rule', 'The promotion cannot be applied at the moment.'],
                $candidate->stacking === Stacking::Exclusive && $applied => [VerdictKind::Skipped, 'not_combinable', 'The promotion does not combine with the promotions applied before it.'],
                default => $this->refusal($stage, $candidate, $state),
            };

            if ($refusal !== null) {
                $state = $this->refuse($session, $stage, $candidate, $state, ...$refusal);

                continue;
            }

            $lines = $this->eligibleLines($candidate, $state);

            if (! is_array($lines)) {
                $state = $this->refuse($session, $stage, $candidate, $state, VerdictKind::Rejected, $lines->reason ?? 'no_eligible_lines', $lines->message ?? '');

                continue;
            }

            [$next, $total, $hasGifts] = $this->effects($stage, $candidate, $state, $lines);

            $refused = match (true) {
                ! $total->isPositive() && ! $hasGifts => ['no_discount', 'The promotion gives no discount on this quote.'],
                $candidate->budget instanceof Money && $candidate->spent instanceof Money && $total->compare($candidate->budget->minus($candidate->spent)) > 0 => ['budget_exhausted', 'The budget of the promotion is spent.'],
                $candidate->campaignBudget instanceof CampaignBudget && ! $candidate->campaignBudget->canAfford($total->amountMinor, 1) => ['campaign_budget_exhausted', 'The budget of the campaign is spent.'],
                default => null,
            };

            if ($refused !== null) {
                $state = $this->refuse($session, $stage, $candidate, $state, VerdictKind::Rejected, ...$refused);

                continue;
            }

            $state = $next;
            $applied = true;
            $session->record($this->verdict($stage, $candidate, VerdictKind::Applied, null, $total));

            if ($candidate->stacking === Stacking::Exclusive || $candidate->stopFurther) {
                $stoppedBy = $candidate->summary->code;
            }
        }

        return $state;
    }

    /** @return array{VerdictKind, string, string}|null the first limit, campaign, or delivery check the candidate fails */
    private function refusal(PromotionStage $stage, CandidatePromotion $candidate, QuoteState $state): ?array
    {
        $customer = $state->context()->customer;
        $campaignBudget = $candidate->campaignBudget;

        return match (true) {
            $candidate->campaignId !== null && ! $candidate->campaignActive => [VerdictKind::Rejected, 'campaign_not_active', 'The campaign of the promotion is not active.'],
            $campaignBudget instanceof CampaignBudget && $campaignBudget->usageCap !== null && $campaignBudget->used >= $campaignBudget->usageCap => [VerdictKind::Rejected, 'campaign_usage_cap_reached', 'The campaign usage cap has been reached.'],
            $campaignBudget instanceof CampaignBudget && $campaignBudget->spendCapMinor !== null && $campaignBudget->spentMinor >= $campaignBudget->spendCapMinor => [VerdictKind::Rejected, 'campaign_budget_exhausted', 'The budget of the campaign is spent.'],
            $stage === PromotionStage::Delivery && $state->delivery === null => [VerdictKind::Rejected, 'no_delivery', 'The quote has no delivery to discount.'],
            $candidate->usageLimit !== null && $candidate->used >= $candidate->usageLimit => [VerdictKind::Rejected, 'usage_limit_reached', 'The promotion has been used up.'],
            $candidate->perCustomerLimit !== null && $customer === null => [VerdictKind::Rejected, 'customer_required', 'The promotion is only for a known customer.'],
            $candidate->perCustomerLimit !== null && $candidate->customerUsed >= $candidate->perCustomerLimit => [VerdictKind::Rejected, 'per_customer_limit_reached', 'The customer has used the promotion as often as allowed.'],
            default => null,
        };
    }

    /**
     * Line filters first, in declared order, each narrowing the eligible lines; then qualifiers, in declared order.
     * Auto-added gift lines are excluded from eligible lines so gifts do not compound into further discounts.
     *
     * @return list<string>|ConditionOutcome the eligible lines, or the failure
     *
     * @throws InvalidRuleResult
     */
    private function eligibleLines(CandidatePromotion $candidate, QuoteState $state): array|ConditionOutcome
    {
        $lines = array_values(array_map(
            static fn (QuoteLine $line): string => $line->lineId,
            array_filter($state->lines, static fn (QuoteLine $line): bool => ! $line->isGift),
        ));
        $filters = [];
        $qualifiers = [];

        foreach ($candidate->conditions as $rule) {
            [$condition, $params] = self::condition($rule);

            if ($condition->role() === ConditionRole::LineFilter) {
                $filters[] = [$rule->key, $condition, $params];
            } else {
                $qualifiers[] = [$rule->key, $condition, $params];
            }
        }

        foreach ($filters as [$key, $condition, $params]) {
            $outcome = $condition->evaluate(new ConditionInput($state, $candidate->summary, $params, $lines));

            if (! $outcome->passed) {
                return $outcome;
            }

            $kept = self::kept($key, $outcome->lines, $lines);
            $lines = array_values(array_filter($lines, static fn (string $line): bool => in_array($line, $kept, true)));

            if ($lines === []) {
                return ConditionOutcome::fail('no_eligible_lines', 'No line of the quote is eligible for the promotion.');
            }
        }

        foreach ($qualifiers as [$key, $condition, $params]) {
            $outcome = $condition->evaluate(new ConditionInput($state, $candidate->summary, $params, $lines));

            if (! $outcome->passed) {
                return $outcome;
            }
        }

        return $lines;
    }

    /**
     * The effects in declared order on a scratch state, each seeing what the earlier ones took off.
     *
     * @param  list<string>  $lines
     * @return array{QuoteState, Money, bool} the scratch state, the total taken off, and whether any gift was added
     *
     * @throws InvalidRuleResult
     */
    private function effects(PromotionStage $stage, CandidatePromotion $candidate, QuoteState $state, array $lines): array
    {
        $next = $state;
        $total = Money::zero($state->currency());
        $hasGifts = false;
        $promotion = $candidate->summary;

        foreach ($candidate->effects as $rule) {
            [$effect, $params] = self::effect($rule);
            $input = new EffectInput($next, $promotion, $params, $lines, $this->config->rounding);
            $answer = $effect->apply($input);

            foreach ($this->guard->discounts($rule->key, $answer) as $returned) {
                $discount = $this->guard->clamp($rule->key, $stage, $returned, $next, $lines);

                if ($discount->amount->isZero()) {
                    continue;
                }

                $next = $next->withAdjustment(new Adjustment(
                    $discount->target,
                    $discount->lineId,
                    $discount->amount->negate(),
                    $stage->stageKey(),
                    $promotion->name,
                    $promotion->id->value,
                    $candidate->couponCode(),
                ));
                $total = $total->plus($discount->amount);
            }

            if ($effect instanceof GiftPromotionEffect) {
                $gifts = $effect->applyGifts($input, $candidate->couponCode());
                [$next, $appliedGift] = $this->applyGifts($rule->key, $promotion->id->value, $gifts, $next);
                $hasGifts = $hasGifts || $appliedGift;
            }
        }

        return [$next, $total, $hasGifts];
    }

    /**
     * Validates gift lines and selectable offers returned by a {@see GiftPromotionEffect} (PRM-05).
     *
     * @return array{QuoteState, bool}
     *
     * @throws InvalidRuleResult
     */
    private function applyGifts(string $ruleKey, string $promotionId, GiftEffectResult $gifts, QuoteState $state): array
    {
        if ($gifts->isEmpty()) {
            return [$state, false];
        }

        $next = $state;

        foreach ($gifts->giftLines as $line) {
            if (! $line->isGift) {
                throw new InvalidRuleResult($ruleKey, 'a gift effect line must have isGift = true');
            }

            if (! $line->unitPrice->isZero()) {
                throw new InvalidRuleResult($ruleKey, 'a gift effect line must have zero unit price');
            }

            if (! $line->unitPrice->currency->equals($state->currency())) {
                throw new InvalidRuleResult($ruleKey, "a gift line is in {$line->unitPrice->currency->code}, the quote is in {$state->currency()->code}");
            }

            if ($line->giftPromotionId !== $promotionId) {
                throw new InvalidRuleResult($ruleKey, 'a gift line must carry the owning promotion id');
            }

            $next = $next->withGiftLine($line);
        }

        foreach ($gifts->giftOffers as $offer) {
            if ($offer->promotionId !== $promotionId) {
                throw new InvalidRuleResult($ruleKey, 'a selectable gift offer must carry the owning promotion id');
            }

            $next = $next->withGiftOffer($offer);
        }

        return [$next, true];
    }

    private function refuse(PromotionSession $session, PromotionStage $stage, CandidatePromotion $candidate, QuoteState $state, VerdictKind $kind, string $reason, string $message): QuoteState
    {
        $session->record($this->verdict($stage, $candidate, $kind, $reason, null));

        if ($candidate->presentedCode() === null) {
            return $state;
        }

        return $state->withRejection(new Rejection($stage->stageKey(), $reason, $message, $candidate->summary->id->value, $candidate->presentedCode()));
    }

    private function verdict(PromotionStage $stage, CandidatePromotion $candidate, VerdictKind $kind, ?string $reason, ?Money $discount): PromotionVerdict
    {
        $promotion = $candidate->summary;

        return new PromotionVerdict($promotion->id, $promotion->code, $promotion->name, $stage, $candidate->couponCode(), $kind, $reason, $discount);
    }

    /**
     * What a line filter kept: a list of lines it was given (a plugin is not trusted, so its answer is checked as data).
     *
     * @param  list<string>  $lines
     * @return list<string>
     *
     * @throws InvalidRuleResult
     */
    private static function kept(string $key, mixed $kept, array $lines): array
    {
        if (! is_array($kept) || ! array_is_list($kept)) {
            throw new InvalidRuleResult($key, 'a line filter must pass with the list of lines that stay eligible');
        }

        $valid = [];

        foreach ($kept as $line) {
            if (! is_string($line) || ! in_array($line, $lines, true)) {
                throw new InvalidRuleResult($key, 'a line filter returned a line that was not eligible: '.(is_string($line) ? "'{$line}'" : get_debug_type($line)));
            }

            $valid[] = $line;
        }

        return $valid;
    }

    /** @return array{PromotionCondition, RuleParams} */
    private static function condition(LoadedRule $rule): array
    {
        return $rule->rule instanceof PromotionCondition && $rule->params instanceof RuleParams
            ? [$rule->rule, $rule->params]
            : throw new LogicException("Condition '{$rule->key}' is not loaded.");
    }

    /** @return array{PromotionEffect, RuleParams} */
    private static function effect(LoadedRule $rule): array
    {
        return $rule->rule instanceof PromotionEffect && $rule->params instanceof RuleParams
            ? [$rule->rule, $rule->params]
            : throw new LogicException("Effect '{$rule->key}' is not loaded.");
    }
}
