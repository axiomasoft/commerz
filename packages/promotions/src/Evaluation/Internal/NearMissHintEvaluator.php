<?php

declare(strict_types=1);

namespace Commerz\Promotions\Evaluation\Internal;

use Brick\Math\BigInteger;
use Commerz\Promotions\Campaigns\Data\CampaignBudget;
use Commerz\Promotions\Configuration\PromotionsOptions;
use Commerz\Promotions\Definitions\Enums\VerdictKind;
use Commerz\Promotions\Rules\Contracts\NearMissCondition;
use Commerz\Promotions\Rules\Contracts\PromotionCondition;
use Commerz\Promotions\Rules\Data\ConditionInput;
use Commerz\Promotions\Rules\Data\RuleParams;
use Commerz\Promotions\Rules\Enums\ConditionRole;
use Commerz\Quotes\Data\NearMissHint;
use Commerz\Quotes\Data\QuoteLine;
use Commerz\Quotes\Data\QuoteState;
use Packman\Attributes\Internal;

/**
 * Evaluates rejected promotions from the current {@see PromotionSession} to produce storefront {@see NearMissHint}s
 * for threshold qualifiers (e.g. `cart.subtotal_at_least`, `cart.quantity_at_least`).
 *
 * A promotion emits a hint only when:
 * - it was rejected during evaluation (not applied, not skipped by exclusivity/stopFurther, not unsupported/exhausted),
 * - its line filters still leave eligible non-gift lines,
 * - all non-near-miss qualifiers pass, and
 * - at least one {@see NearMissCondition} reports a positive gap meeting `nearMissMinProgressBp`.
 */
#[Internal]
final readonly class NearMissHintEvaluator implements NearMissEvaluator
{
    public function __construct(
        private PromotionSessions $sessions,
        private PromotionsOptions $options,
    ) {}

    /** @return list<NearMissHint> */
    public function evaluate(QuoteState $state): array
    {
        $session = $this->sessions->find($state->input);

        if ($session === null) {
            return [];
        }

        $nonGiftLines = array_values(array_map(
            static fn (QuoteLine $line): string => $line->lineId,
            array_filter($state->lines, static fn (QuoteLine $line): bool => ! $line->isGift),
        ));

        if ($nonGiftLines === []) {
            return [];
        }

        $hints = [];

        foreach ($session->allCandidates() as $candidate) {
            if (! $this->isEligibleForNearMiss($candidate, $session, $state)) {
                continue;
            }

            $hint = $this->hintForCandidate($candidate, $state, $nonGiftLines);

            if ($hint instanceof NearMissHint && $this->meetsProgressThreshold($hint)) {
                $hints[] = $hint;
            }
        }

        return $hints;
    }

    private function isEligibleForNearMiss(CandidatePromotion $candidate, PromotionSession $session, QuoteState $state): bool
    {
        if ($candidate->unsupported() !== null) {
            return false;
        }

        if ($candidate->campaignId !== null && ! $candidate->campaignActive) {
            return false;
        }

        if ($candidate->campaignBudget instanceof CampaignBudget && $candidate->campaignBudget->isExhausted()) {
            return false;
        }

        if ($candidate->usageLimit !== null && $candidate->used >= $candidate->usageLimit) {
            return false;
        }

        if ($candidate->perCustomerLimit !== null && ($state->context()->customer === null || $candidate->customerUsed >= $candidate->perCustomerLimit)) {
            return false;
        }

        $verdict = $session->verdictFor($candidate->summary->id->value);

        return $verdict !== null && $verdict->kind === VerdictKind::Rejected;
    }

    /** @param  list<string>  $allLines */
    private function hintForCandidate(CandidatePromotion $candidate, QuoteState $state, array $allLines): ?NearMissHint
    {
        $lines = $allLines;
        $filters = [];
        $qualifiers = [];

        foreach ($candidate->conditions as $rule) {
            if (! $rule->rule instanceof PromotionCondition || ! $rule->params instanceof RuleParams) {
                return null;
            }

            if ($rule->rule->role() === ConditionRole::LineFilter) {
                $filters[] = [$rule->rule, $rule->params];
            } else {
                $qualifiers[] = [$rule->rule, $rule->params];
            }
        }

        foreach ($filters as [$condition, $params]) {
            $outcome = $condition->evaluate(new ConditionInput($state, $candidate->summary, $params, $lines));

            if (! $outcome->passed || ! is_array($outcome->lines)) {
                return null;
            }

            $kept = $outcome->lines;
            $lines = array_values(array_filter($lines, static fn (string $line): bool => in_array($line, $kept, true)));

            if ($lines === []) {
                return null;
            }
        }

        $firstHint = null;

        foreach ($qualifiers as [$condition, $params]) {
            $input = new ConditionInput($state, $candidate->summary, $params, $lines);
            $outcome = $condition->evaluate($input);

            if ($outcome->passed) {
                continue;
            }

            if (! $condition instanceof NearMissCondition) {
                return null; // a non-threshold qualifier failed (e.g. customer group)
            }

            $hint = $condition->nearMiss($input);

            if ($hint === null) {
                return null;
            }

            $firstHint ??= $hint;
        }

        return $firstHint;
    }

    private function meetsProgressThreshold(NearMissHint $hint): bool
    {
        $minBp = $this->options->nearMissMinProgressBp;

        if ($minBp <= 0) {
            return true;
        }

        if ($hint->missingAmount !== null && $hint->thresholdAmount !== null) {
            $threshold = $hint->thresholdAmount->amountMinor;
            $current = max(0, $threshold - $hint->missingAmount->amountMinor);

            // current / threshold >= minBp / 10000  <=>  current * 10000 >= threshold * minBp (exact BigInteger, zero float)
            return BigInteger::of($current)->multipliedBy(10_000)
                ->compareTo(BigInteger::of($threshold)->multipliedBy($minBp)) >= 0;
        }

        if ($hint->missingQuantity !== null && $hint->thresholdQuantity !== null) {
            $threshold = $hint->thresholdQuantity;
            $current = max(0, $threshold - $hint->missingQuantity);

            return BigInteger::of($current)->multipliedBy(10_000)
                ->compareTo(BigInteger::of($threshold)->multipliedBy($minBp)) >= 0;
        }

        return true;
    }
}
