<?php

declare(strict_types=1);

namespace Commerz\Promotions\Internal;

use Commerz\Money\Data\Money;
use Commerz\Pricing\Data\Adjustment;
use Commerz\Pricing\Data\PricedLine;
use Commerz\Pricing\Data\PricingState;
use Commerz\Pricing\Data\Rejection;
use Commerz\Promotions\Data\AdjustmentProposal;
use Commerz\Promotions\Data\PromotionCandidate;
use Commerz\Promotions\Data\PromotionInput;
use Commerz\Promotions\Enums\PromotionStage;
use Commerz\Promotions\Enums\RejectionReason;
use Commerz\Promotions\Enums\StackingMode;

/**
 * Чистый движок одной стадии (promotions-and-coupons.md §3). Детерминизм: priority DESC, code ASC.
 * Последовательное применение: каждая акция видит цену после предыдущих.
 */
final readonly class PromotionEngine
{
    public function __construct(private PluginLookup $plugins) {}

    /** @param list<PromotionCandidate> $candidates */
    public function run(PromotionStage $stage, PricingState $state, array $candidates): PricingState
    {
        $ordered = array_values(array_filter($candidates, static fn (PromotionCandidate $c): bool => $c->stage === $stage));
        usort($ordered, static fn (PromotionCandidate $a, PromotionCandidate $b): int => [$b->priority, $a->code] <=> [$a->priority, $b->code]);

        $stoppedBy = null;
        $appliedAny = false;

        foreach ($ordered as $candidate) {
            $reject = match (true) {
                $stoppedBy !== null => [RejectionReason::StoppedBy, $stoppedBy],
                $candidate->stacking === StackingMode::Exclusive && $appliedAny => [RejectionReason::ExclusiveConflict, null],
                default => null,
            };
            if ($reject !== null) {
                $state = $this->reject($state, $candidate, $reject[0], $reject[1]);
                continue;
            }

            $input = new PromotionInput($state->context, $state->lines, $state->itemsTotal(), $state->enteredCodes);

            foreach ($candidate->conditions as $condition) {
                $result = $this->plugins->condition($condition->key)->evaluate($condition->config, $input);
                if (! $result->matched) {
                    $state = $this->reject($state, $candidate, RejectionReason::ConditionNotMet, $result->reason);
                    continue 2;
                }
            }

            $proposals = [];
            foreach ($candidate->effects as $effect) {
                array_push($proposals, ...$this->plugins->effect($effect->key)->propose($effect->config, $input));
            }

            [$lines, $adjustments] = $this->applyClamped($state->lines, $proposals, $candidate);
            if ($adjustments === []) {
                $state = $this->reject($state, $candidate, RejectionReason::NothingToDiscount, null);
                continue;
            }

            $state = $state->with($lines, $adjustments, []);
            $appliedAny = true;
            if ($candidate->stacking->stopsFurther()) {
                $stoppedBy = $candidate->code;
            }
        }

        return $state;
    }

    /**
     * Обрезка: строка не уходит ниже нуля. Скидки уровня заказа без lineId здесь не допускаются —
     * эффекты обязаны распределить сумму (Money::allocate), чтобы возвраты были построчными.
     *
     * @param  list<PricedLine>  $lines
     * @param  list<AdjustmentProposal>  $proposals
     * @return array{list<PricedLine>, list<Adjustment>}
     */
    private function applyClamped(array $lines, array $proposals, PromotionCandidate $candidate): array
    {
        $byId = array_column(array_map(static fn (PricedLine $l): array => [$l->lineId, $l], $lines), 1, 0);
        $adjustments = [];

        foreach ($proposals as $proposal) {
            $line = $byId[$proposal->lineId] ?? throw new \LogicException("Effect proposed unknown line [{$proposal->lineId}].");
            $amount = $proposal->amount->min($line->current());
            if (! $amount->isPositive()) {
                continue;
            }
            $byId[$line->lineId] = $line->withExtraDiscount($amount);
            $adjustments[] = new Adjustment($candidate->source(), $candidate->label, $line->lineId, $amount, $candidate->activatedByCode);
        }

        return [array_values($byId), $adjustments];
    }

    private function reject(PricingState $state, PromotionCandidate $c, RejectionReason $reason, ?string $detail): PricingState
    {
        $text = $detail === null ? $reason->value : "{$reason->value}:{$detail}";

        return $state->with($state->lines, [], [new Rejection($c->source(), $text, $c->activatedByCode)]);
    }
}
