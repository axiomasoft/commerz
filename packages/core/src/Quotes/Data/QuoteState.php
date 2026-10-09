<?php

declare(strict_types=1);

namespace Commerz\Quotes\Data;

use Commerz\Attributes\Api;
use Commerz\Context\CommerceContext;
use Commerz\Errors\InvalidCommand;
use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Money\MoneyAllocator;
use Commerz\Quotes\Enums\AdjustmentTarget;

/**
 * The immutable state passed from stage to stage. The base stage prices the input lines; later stages add
 * adjustments, delivery, taxes and rejections; the totals stage settles it. Any change to amounts after settling
 * drops the totals, so a stage after `totals` cannot leave a result that does not add up.
 */
#[Api(since: '0.1')]
final readonly class QuoteState
{
    /**
     * @param  list<QuoteLine>  $lines  priced lines, in input order
     * @param  list<Adjustment>  $adjustments
     * @param  list<TaxLine>  $taxLines
     * @param  list<Rejection>  $rejections
     * @param  list<QuotedLine>  $settledLines  set by the totals stage together with `$totals`
     */
    private function __construct(
        public QuoteInput $input,
        public array $lines,
        public array $adjustments,
        public array $taxLines,
        public ?DeliveryCharge $delivery,
        public array $rejections,
        public array $settledLines,
        public ?QuoteTotals $totals,
    ) {}

    public static function start(QuoteInput $input): self
    {
        return new self($input, [], [], [], null, [], [], null);
    }

    public function context(): CommerceContext
    {
        return $this->input->context;
    }

    public function currency(): Currency
    {
        return $this->input->context->currency;
    }

    public function line(string $lineId): ?QuoteLine
    {
        return array_find($this->lines, static fn (QuoteLine $line): bool => $line->lineId === $lineId);
    }

    /** The line's subtotal after its own line adjustments: the base for later line discounts and allocation. */
    public function lineNet(string $lineId): Money
    {
        $line = $this->line($lineId) ?? throw new InvalidCommand('lineId', "'{$lineId}' is not a priced line", 'quotes.unknown_line');
        $net = $line->subtotal();

        foreach ($this->adjustments as $adjustment) {
            if ($adjustment->target === AdjustmentTarget::Line && $adjustment->lineId === $lineId) {
                $net = $net->plus($adjustment->amount);
            }
        }

        return $net;
    }

    /** Sum of all line nets, before order-level adjustments. */
    public function itemsNet(): Money
    {
        $total = Money::zero($this->currency());

        foreach ($this->lines as $line) {
            $total = $total->plus($this->lineNet($line->lineId));
        }

        return $total;
    }

    /**
     * Shares of every order-level adjustment carried by the lines, in adjustment order then line order: each one is
     * spread by MoneyAllocator over what is left of each line (its net after line adjustments and earlier shares), so
     * no line goes below zero and the shares add up exactly. The same split the totals stage settles, so a tax computed
     * on it matches the settled lines to the minor unit.
     *
     * @return list<Adjustment> allocated shares (target Order, lineId set); zero shares are omitted
     *
     * @throws InvalidCommand quotes.negative_total when order-level adjustments exceed the items total
     */
    public function allocatedOrderAdjustments(): array
    {
        // Line ids such as '1' become integer array keys, so ids and amounts are kept as parallel lists.
        $ids = [];
        $remaining = [];

        foreach ($this->lines as $line) {
            $ids[] = $line->lineId;
            $remaining[] = $this->lineNet($line->lineId)->amountMinor;
        }

        $allocated = [];

        foreach ($this->adjustments as $adjustment) {
            if ($adjustment->target !== AdjustmentTarget::Order) {
                continue;
            }

            if (array_sum($remaining) + $adjustment->amount->amountMinor < 0) {
                throw new InvalidCommand('adjustments', 'order-level adjustments exceed the items total', 'quotes.negative_total');
            }

            foreach (MoneyAllocator::allocate($adjustment->amount, array_values($remaining)) as $index => $share) {
                if ($share->isZero()) {
                    continue;
                }

                $allocated[] = $adjustment->allocatedTo($ids[$index], $share);
                $remaining[$index] += $share->amountMinor;
            }
        }

        return $allocated;
    }

    /** Delivery price after delivery adjustments, or null without a delivery charge. */
    public function deliveryNet(): ?Money
    {
        if ($this->delivery === null) {
            return null;
        }

        $net = $this->delivery->price;

        foreach ($this->adjustments as $adjustment) {
            if ($adjustment->target === AdjustmentTarget::Delivery) {
                $net = $net->plus($adjustment->amount);
            }
        }

        return $net;
    }

    /** Adds the line, or replaces the line with the same id. The id must come from the input. */
    public function withLine(QuoteLine $line): self
    {
        if ($this->input->line($line->lineId) === null) {
            throw new InvalidCommand('lineId', "'{$line->lineId}' is not an input line", 'quotes.unknown_line');
        }

        $this->assertCurrency($line->unitPrice, 'unitPrice');
        $lines = $this->lines;
        $index = array_find_key($lines, static fn (QuoteLine $existing): bool => $existing->lineId === $line->lineId);

        if ($index === null) {
            $lines[] = $line;
        } else {
            $lines[$index] = $line;
        }

        return $this->changed(lines: $lines);
    }

    public function withAdjustment(Adjustment $adjustment): self
    {
        $this->assertCurrency($adjustment->amount, 'amount');

        if ($adjustment->lineId !== null && $this->line($adjustment->lineId) === null) {
            throw new InvalidCommand('lineId', "'{$adjustment->lineId}' is not a priced line", 'quotes.unknown_line');
        }

        return $this->changed(adjustments: [...$this->adjustments, $adjustment]);
    }

    public function withDelivery(?DeliveryCharge $delivery): self
    {
        if ($delivery !== null) {
            $this->assertCurrency($delivery->price, 'price');
        }

        return $this->changed(delivery: $delivery, clearDelivery: $delivery === null);
    }

    public function withTaxLine(TaxLine $tax): self
    {
        $this->assertCurrency($tax->amount, 'amount');

        if ($tax->lineId !== null && $this->line($tax->lineId) === null) {
            throw new InvalidCommand('lineId', "'{$tax->lineId}' is not a priced line", 'quotes.unknown_line');
        }

        return $this->changed(taxLines: [...$this->taxLines, $tax]);
    }

    /** Recording a rejection does not change amounts, so it keeps settled totals. */
    public function withRejection(Rejection $rejection): self
    {
        return new self(
            $this->input,
            $this->lines,
            $this->adjustments,
            $this->taxLines,
            $this->delivery,
            [...$this->rejections, $rejection],
            $this->settledLines,
            $this->totals,
        );
    }

    /**
     * Settles the quote; called by the totals stage.
     *
     * @param  list<QuotedLine>  $lines
     */
    public function withTotals(array $lines, QuoteTotals $totals): self
    {
        return new self(
            $this->input,
            $this->lines,
            $this->adjustments,
            $this->taxLines,
            $this->delivery,
            $this->rejections,
            $lines,
            $totals,
        );
    }

    /**
     * @param  list<QuoteLine>|null  $lines
     * @param  list<Adjustment>|null  $adjustments
     * @param  list<TaxLine>|null  $taxLines
     */
    private function changed(
        ?array $lines = null,
        ?array $adjustments = null,
        ?array $taxLines = null,
        ?DeliveryCharge $delivery = null,
        bool $clearDelivery = false,
    ): self {
        return new self(
            $this->input,
            $lines ?? $this->lines,
            $adjustments ?? $this->adjustments,
            $taxLines ?? $this->taxLines,
            $clearDelivery ? null : ($delivery ?? $this->delivery),
            $this->rejections,
            [],
            null,
        );
    }

    private function assertCurrency(Money $amount, string $field): void
    {
        if (! $amount->currency->equals($this->currency())) {
            throw new InvalidCommand($field, "{$amount->currency->code} differs from the quote currency {$this->currency()->code}", 'quotes.currency_mismatch');
        }
    }
}
