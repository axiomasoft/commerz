<?php

declare(strict_types=1);

namespace Commerz\Pricing\Calculation\Internal;

use Commerz\Errors\InvalidCommand;
use Commerz\Money\Money;
use Commerz\Plugins\Contracts\Contributions;
use Commerz\Ports\Customers\Contracts\CustomerDirectory;
use Commerz\Pricing\Calculation\Contracts\TaxCalculator;
use Commerz\Pricing\Calculation\Data\TaxableItem;
use Commerz\Pricing\Calculation\Data\TaxRequest;
use Commerz\Pricing\Common\Internal\PricingConfig;
use Commerz\Quotes\Contracts\QuoteStage;
use Commerz\Quotes\Data\QuoteState;
use Commerz\Quotes\Data\TaxLine;
use Commerz\Quotes\Enums\AdjustmentTarget;
use Commerz\Quotes\Enums\QuoteAnchor;
use Commerz\Quotes\Enums\StagePosition;
use Commerz\Quotes\Exceptions\QuoteFailed;
use Illuminate\Contracts\Container\Container;

/**
 * Taxes every line and the delivery charge on what is left after all discounts: the line net plus the line's share of
 * each order-level adjustment (`QuoteState::allocatedOrderAdjustments()`, the very split the totals stage settles) and
 * the delivery net. The calculator is the configured `tax_calculator` contribution and its answer is validated. A
 * tax-exempt buyer gets no tax lines. Pure: it writes nothing and reads time from the context. Dependencies are looked
 * up per run, so a port or a calculator rebound after the registry froze (a test fake) is still used.
 */
final readonly class TaxStage implements QuoteStage
{
    public function __construct(private Container $container) {}

    public function key(): string
    {
        return QuoteAnchor::Tax->value;
    }

    public function anchor(): QuoteAnchor
    {
        return QuoteAnchor::Tax;
    }

    public function position(): StagePosition
    {
        return StagePosition::At;
    }

    public function apply(QuoteState $state): QuoteState
    {
        if ($state->lines === [] && $state->delivery === null) {
            return $state;
        }

        $customer = $state->context()->customer;

        if ($customer !== null && $this->container->make(CustomerDirectory::class)->find($customer)?->taxExempt === true) {
            return $state;
        }

        $config = $this->container->make(PricingConfig::class);
        $items = $this->items($state, $config);

        if ($items === []) {
            return $state;
        }

        $calculator = $this->container->make(Contributions::class)->get(TaxCalculator::KIND, $config->taxCalculator, TaxCalculator::class);
        $request = new TaxRequest($state->context(), $state->input->delivery?->address, $items);
        $result = $calculator->calculate($request);

        $this->container->make(TaxResultValidator::class)->assertValid($config->taxCalculator, $request, $result);

        $included = [];

        foreach ($items as $item) {
            $included[$item->key()] = $item->included;
        }

        foreach ($result->amounts as $amount) {
            $state = $state->withTaxLine(new TaxLine(
                $amount->target,
                $amount->lineId,
                $amount->code,
                $amount->rate,
                $amount->amount,
                $included[$amount->itemKey()],
            ));
        }

        return $state;
    }

    /** @return list<TaxableItem> lines in quote order, then the delivery */
    private function items(QuoteState $state, PricingConfig $config): array
    {
        try {
            $shares = $state->allocatedOrderAdjustments();
        } catch (InvalidCommand $e) {
            throw new QuoteFailed($this->key(), 'negative_total', $e->getMessage(), $e);
        }

        $bases = [];

        foreach ($shares as $share) {
            $bases[(string) $share->lineId] = ($bases[(string) $share->lineId] ?? Money::zero($state->currency()))->plus($share->amount);
        }

        $items = [];

        foreach ($state->lines as $line) {
            $base = $state->lineNet($line->lineId)->plus($bases[$line->lineId] ?? Money::zero($state->currency()));

            if ($base->isNegative()) {
                throw new QuoteFailed($this->key(), 'negative_line', "line '{$line->lineId}' is discounted below zero");
            }

            $items[] = new TaxableItem(
                AdjustmentTarget::Line,
                $line->lineId,
                $line->sellable->ref,
                $line->sellable->taxClass ?? $config->defaultTaxClass,
                $line->quantity,
                $base,
                $line->taxIncluded,
            );
        }

        $deliveryNet = $state->deliveryNet();

        if ($state->delivery !== null && $deliveryNet !== null && $config->deliveryTaxClass !== null) {
            if ($deliveryNet->isNegative()) {
                throw new QuoteFailed($this->key(), 'negative_delivery', 'delivery is discounted below zero');
            }

            $items[] = new TaxableItem(AdjustmentTarget::Delivery, null, null, $config->deliveryTaxClass, 1, $deliveryNet, $state->delivery->taxIncluded);
        }

        return $items;
    }
}
