<?php

declare(strict_types=1);

namespace Commerz\Quotes\Internal;

use Commerz\Ports\Prices\Contracts\PriceResolver;
use Commerz\Ports\Prices\Data\ResolvePrice;
use Commerz\Quotes\Contracts\QuoteStage;
use Commerz\Quotes\Data\QuoteLine;
use Commerz\Quotes\Data\QuoteLineInput;
use Commerz\Quotes\Data\QuoteState;
use Commerz\Quotes\Enums\QuoteAnchor;
use Commerz\Quotes\Enums\StagePosition;
use Commerz\Sellable\SellingPlan;
use Illuminate\Contracts\Container\Container;
use LogicException;
use Packman\Attributes\Internal;

/**
 * Prices every input line with one `PriceResolver::resolveMany()` call; the sellable's base price is the fallback.
 * If a line (or quote) carries a {@see SellingPlan}, its pricing policy is applied to the resolved
 * unit price. The resolver is looked up per run, so a port rebound after the registry froze (a test fake) is still used.
 */
#[Internal]
final readonly class BaseStage implements QuoteStage
{
    public function __construct(private Container $container) {}

    public function key(): string
    {
        return QuoteAnchor::Base->value;
    }

    public function anchor(): QuoteAnchor
    {
        return QuoteAnchor::Base;
    }

    public function position(): StagePosition
    {
        return StagePosition::At;
    }

    public function apply(QuoteState $state): QuoteState
    {
        $inputs = $state->input->lines;

        if ($inputs === []) {
            return $state;
        }

        $prices = $this->container->make(PriceResolver::class)->resolveMany(
            array_map(
                static fn (QuoteLineInput $line): ResolvePrice => new ResolvePrice(
                    $line->sellable->ref,
                    $line->quantity,
                    $line->sellable->basePrice ?? $line->sellable->unitPrice?->amount,
                ),
                $inputs,
            ),
            $state->context(),
        );

        if (count($prices) !== count($inputs)) {
            throw new LogicException('PriceResolver returned '.count($prices).' prices for '.count($inputs).' requests.');
        }

        foreach ($inputs as $index => $input) {
            $price = $prices[$index];
            $sellingPlan = $input->sellingPlan ?? $state->input->sellingPlan;
            $unitPrice = $sellingPlan?->unitPriceForCycle($price->unit, 1, $state->input->rounding) ?? $price->unit;
            $compareAt = $price->compareAt ?? ($unitPrice->compare($price->unit) < 0 ? $price->unit : null);
            $source = $sellingPlan !== null && ! $unitPrice->equals($price->unit)
                ? "{$price->source}+selling_plan:{$sellingPlan->id}"
                : $price->source;

            $state = $state->withLine(new QuoteLine(
                $input->lineId,
                $input->sellable,
                $input->quantity,
                $unitPrice,
                $compareAt,
                $price->taxIncluded,
                $source,
                $input->sellable->unitPrice?->withAmount($unitPrice),
                $state->input->rounding,
                isGift: false,
                sellingPlan: $sellingPlan,
            ));
        }

        return $state;
    }
}
