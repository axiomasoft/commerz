<?php

declare(strict_types=1);

namespace Commerz\Promotions\Evaluation\Internal;

use Commerz\Money\Money;
use Commerz\Promotions\Rules\Data\Discount;
use Commerz\Promotions\Rules\Enums\PromotionStage;
use Commerz\Promotions\Rules\Exceptions\InvalidRuleResult;
use Commerz\Promotions\Rules\Internal\Effects\OrderNet;
use Commerz\Quotes\Data\QuoteState;
use Commerz\Quotes\Enums\AdjustmentTarget;
use Packman\Attributes\Internal;

/**
 * An effect is never trusted (PRM-05): every discount must be a `Discount` in the quote's currency, aimed at a target
 * its stage may change, at a line the conditions left eligible, and not negative. What passes is clamped to what is
 * left of its target, so a line, the order or the delivery never goes below zero.
 */
#[Internal]
final class DiscountGuard
{
    /**
     * The answer of an effect as a list (its elements are checked one by one by `clamp()`).
     *
     * @return list<mixed>
     *
     * @throws InvalidRuleResult
     */
    public function discounts(string $rule, mixed $discounts): array
    {
        return is_array($discounts) && array_is_list($discounts)
            ? $discounts
            : throw new InvalidRuleResult($rule, 'an effect must return a list of Discount');
    }

    /**
     * @param  list<string>  $lines  the eligible lines
     * @return Discount the discount, its amount cut to what is left of the target (possibly zero)
     *
     * @throws InvalidRuleResult
     */
    public function clamp(string $rule, PromotionStage $stage, mixed $discount, QuoteState $next, array $lines): Discount
    {
        if (! $discount instanceof Discount) {
            throw new InvalidRuleResult($rule, 'an effect must return a list of Discount, got '.get_debug_type($discount));
        }

        if (! $discount->amount->currency->equals($next->currency())) {
            throw new InvalidRuleResult($rule, "a discount is in {$discount->amount->currency->code}, the quote is in {$next->currency()->code}");
        }

        if ($discount->amount->isNegative()) {
            throw new InvalidRuleResult($rule, 'a discount must not be negative');
        }

        if (! in_array($discount->target, self::targets($stage), true)) {
            throw new InvalidRuleResult($rule, "a {$discount->target->value} discount is not allowed in the {$stage->value} stage");
        }

        $left = match ($discount->target) {
            AdjustmentTarget::Line => $this->lineLeft($rule, $discount, $next, $lines),
            AdjustmentTarget::Order => OrderNet::of($next),
            AdjustmentTarget::Delivery => $next->deliveryNet() ?? throw new InvalidRuleResult($rule, 'a delivery discount without a delivery charge'),
        };

        $amount = match (true) {
            ! $left->isPositive() => Money::zero($next->currency()),
            $discount->amount->compare($left) > 0 => $left,
            default => $discount->amount,
        };

        return match ($discount->target) {
            AdjustmentTarget::Line => Discount::line((string) $discount->lineId, $amount),
            AdjustmentTarget::Order => Discount::order($amount),
            AdjustmentTarget::Delivery => Discount::delivery($amount),
        };
    }

    /** @param  list<string>  $lines */
    private function lineLeft(string $rule, Discount $discount, QuoteState $next, array $lines): Money
    {
        if ($discount->lineId === null || ! in_array($discount->lineId, $lines, true)) {
            throw new InvalidRuleResult($rule, "line '{$discount->lineId}' is not one of the eligible lines");
        }

        $line = $next->lineNet($discount->lineId);
        $order = OrderNet::of($next);

        // Order adjustments remain declared and are allocated at settlement. Preserve their total even when a
        // later line discount changes the allocation weights (D23); otherwise their shares can exceed the items.
        return $line->compare($order) > 0 ? $order : $line;
    }

    /** @return list<AdjustmentTarget> */
    private static function targets(PromotionStage $stage): array
    {
        return match ($stage) {
            PromotionStage::Catalog => [AdjustmentTarget::Line],
            PromotionStage::Cart => [AdjustmentTarget::Line, AdjustmentTarget::Order],
            PromotionStage::Delivery => [AdjustmentTarget::Delivery],
        };
    }
}
