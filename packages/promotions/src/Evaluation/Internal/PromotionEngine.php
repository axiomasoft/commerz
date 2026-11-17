<?php

declare(strict_types=1);

namespace Commerz\Promotions\Evaluation\Internal;

use Commerz\Money\Money;
use Commerz\Promotions\Configuration\PromotionsOptions;
use Commerz\Promotions\Definitions\Data\PromotionVerdict;
use Commerz\Promotions\Definitions\Enums\Stacking;
use Commerz\Promotions\Definitions\Enums\VerdictKind;
use Commerz\Promotions\Rules\Contracts\PromotionCondition;
use Commerz\Promotions\Rules\Contracts\PromotionEffect;
use Commerz\Promotions\Rules\Data\ConditionInput;
use Commerz\Promotions\Rules\Data\ConditionOutcome;
use Commerz\Promotions\Rules\Data\EffectInput;
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
 * a code leaves a `Rejection` in the quote. Limits are read from the session snapshot as a hint; they hold when the
 * order is placed. Exceptions of rules other than the refusals described here are not swallowed: the pipeline fails
 * the quote.
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

            [$next, $total] = $this->effects($stage, $candidate, $state, $lines);

            $refused = match (true) {
                ! $total->isPositive() => ['no_discount', 'The promotion gives no discount on this quote.'],
                $candidate->budget instanceof Money && $candidate->spent instanceof Money && $total->compare($candidate->budget->minus($candidate->spent)) > 0 => ['budget_exhausted', 'The budget of the promotion is spent.'],
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

    /** @return array{VerdictKind, string, string}|null the first limit or delivery check the candidate fails */
    private function refusal(PromotionStage $stage, CandidatePromotion $candidate, QuoteState $state): ?array
    {
        $customer = $state->context()->customer;

        return match (true) {
            $stage === PromotionStage::Delivery && $state->delivery === null => [VerdictKind::Rejected, 'no_delivery', 'The quote has no delivery to discount.'],
            $candidate->usageLimit !== null && $candidate->used >= $candidate->usageLimit => [VerdictKind::Rejected, 'usage_limit_reached', 'The promotion has been used up.'],
            $candidate->perCustomerLimit !== null && $customer === null => [VerdictKind::Rejected, 'customer_required', 'The promotion is only for a known customer.'],
            $candidate->perCustomerLimit !== null && $candidate->customerUsed >= $candidate->perCustomerLimit => [VerdictKind::Rejected, 'per_customer_limit_reached', 'The customer has used the promotion as often as allowed.'],
            default => null,
        };
    }

    /**
     * Line filters first, in declared order, each narrowing the eligible lines; then qualifiers, in declared order.
     *
     * @return list<string>|ConditionOutcome the eligible lines, or the failure
     *
     * @throws InvalidRuleResult
     */
    private function eligibleLines(CandidatePromotion $candidate, QuoteState $state): array|ConditionOutcome
    {
        $lines = array_map(static fn (QuoteLine $line): string => $line->lineId, $state->lines);
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
     * @return array{QuoteState, Money} the scratch state and the total taken off
     *
     * @throws InvalidRuleResult
     */
    private function effects(PromotionStage $stage, CandidatePromotion $candidate, QuoteState $state, array $lines): array
    {
        $next = $state;
        $total = Money::zero($state->currency());
        $promotion = $candidate->summary;

        foreach ($candidate->effects as $rule) {
            [$effect, $params] = self::effect($rule);
            $answer = $effect->apply(new EffectInput($next, $promotion, $params, $lines, $this->config->rounding));

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
        }

        return [$next, $total];
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
