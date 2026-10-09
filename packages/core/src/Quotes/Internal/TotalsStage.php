<?php

declare(strict_types=1);

namespace Commerz\Quotes\Internal;

use Commerz\Errors\InvalidCommand;
use Commerz\Money\Money;
use Commerz\Quotes\Contracts\QuoteStage;
use Commerz\Quotes\Data\QuotedLine;
use Commerz\Quotes\Data\QuoteState;
use Commerz\Quotes\Data\QuoteTotals;
use Commerz\Quotes\Enums\AdjustmentTarget;
use Commerz\Quotes\Enums\QuoteAnchor;
use Commerz\Quotes\Enums\StagePosition;
use Commerz\Quotes\Exceptions\QuoteFailed;

/**
 * Settles the quote: every input line must be priced; each order-level adjustment is spread over the lines by
 * `MoneyAllocator` in proportion to what is left of each line (its subtotal after line adjustments and earlier
 * order-level shares), so no line goes below zero and the shares add up exactly; then the totals, which must not be
 * negative.
 */
final readonly class TotalsStage implements QuoteStage
{
    public function key(): string
    {
        return QuoteAnchor::Totals->value;
    }

    public function anchor(): QuoteAnchor
    {
        return QuoteAnchor::Totals;
    }

    public function position(): StagePosition
    {
        return StagePosition::At;
    }

    public function apply(QuoteState $state): QuoteState
    {
        $zero = Money::zero($state->currency());

        foreach ($state->input->lines as $input) {
            if ($state->line($input->lineId) === null) {
                throw new QuoteFailed($this->key(), 'unpriced_line', "line '{$input->lineId}' has no price");
            }
        }

        $lineAdjustments = [];
        $discount = $zero;

        foreach ($state->adjustments as $adjustment) {
            if ($adjustment->target === AdjustmentTarget::Delivery && $state->delivery === null) {
                throw new QuoteFailed($this->key(), 'delivery_without_charge', 'a delivery adjustment without a delivery charge');
            }

            match ($adjustment->target) {
                AdjustmentTarget::Line => $lineAdjustments[(string) $adjustment->lineId][] = $adjustment,
                AdjustmentTarget::Order, AdjustmentTarget::Delivery => null,
            };

            if ($adjustment->target !== AdjustmentTarget::Delivery) {
                $discount = $discount->minus($adjustment->amount);
            }
        }

        foreach ($state->lines as $line) {
            if ($state->lineNet($line->lineId)->isNegative()) {
                throw new QuoteFailed($this->key(), 'negative_line', "line '{$line->lineId}' is discounted below zero");
            }
        }

        try {
            $allocated = [];

            foreach ($state->allocatedOrderAdjustments() as $share) {
                $allocated[(string) $share->lineId][] = $share;
            }
        } catch (InvalidCommand $e) {
            throw new QuoteFailed($this->key(), 'negative_total', $e->getMessage(), $e);
        }

        $deliveryNet = $state->deliveryNet() ?? $zero;

        if ($deliveryNet->isNegative()) {
            throw new QuoteFailed($this->key(), 'negative_delivery', 'delivery is discounted below zero');
        }

        $taxTotal = $zero;
        $excludedTax = $zero;
        $lineTaxes = [];

        foreach ($state->taxLines as $tax) {
            if ($tax->target === AdjustmentTarget::Delivery && $state->delivery === null) {
                throw new QuoteFailed($this->key(), 'delivery_without_charge', 'a delivery tax without a delivery charge');
            }

            $taxTotal = $taxTotal->plus($tax->amount);
            $excludedTax = $tax->included ? $excludedTax : $excludedTax->plus($tax->amount);

            if ($tax->lineId !== null) {
                $lineTaxes[$tax->lineId][] = $tax;
            }
        }

        $settled = [];
        $subtotal = $zero;

        foreach ($state->lines as $line) {
            $adjustments = [...$lineAdjustments[$line->lineId] ?? [], ...$allocated[$line->lineId] ?? []];
            $taxes = $lineTaxes[$line->lineId] ?? [];
            $total = $line->subtotal();

            foreach ($adjustments as $adjustment) {
                $total = $total->plus($adjustment->amount);
            }

            foreach ($taxes as $tax) {
                $total = $tax->included ? $total : $total->plus($tax->amount);
            }

            $subtotal = $subtotal->plus($line->subtotal());
            $settled[] = new QuotedLine(
                $line->lineId,
                $line->sellable,
                $line->quantity,
                $line->unitPrice,
                $line->compareAt,
                $line->taxIncluded,
                $line->priceSource,
                $line->subtotal(),
                $adjustments,
                $taxes,
                $total,
            );
        }

        $total = $subtotal->minus($discount)->plus($deliveryNet)->plus($excludedTax);

        if ($total->isNegative()) {
            throw new QuoteFailed($this->key(), 'negative_total', "the total {$total->toDecimal()} is negative");
        }

        return $state->withTotals($settled, new QuoteTotals($subtotal, $discount, $deliveryNet, $taxTotal, $total));
    }
}
