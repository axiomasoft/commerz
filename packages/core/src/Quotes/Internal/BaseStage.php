<?php

declare(strict_types=1);

namespace Commerz\Quotes\Internal;

use Commerz\Ports\Prices\Contracts\PriceResolver;
use Commerz\Ports\Prices\Data\ResolvePrice;
use Commerz\Quotes\Contracts\QuoteStage;
use Commerz\Quotes\Data\QuoteLine;
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

        $toResolve = [];
        $resolveIndexes = [];

        foreach ($inputs as $index => $line) {
            if ($line->fixedUnitPrice === null) {
                $toResolve[] = new ResolvePrice(
                    $line->sellable->ref,
                    $line->quantity,
                    $line->sellable->basePrice ?? $line->sellable->unitPrice?->amount,
                );
                $resolveIndexes[] = $index;
            }
        }

        $resolvedByIndex = [];

        if ($toResolve !== []) {
            $prices = $this->container->make(PriceResolver::class)->resolveMany($toResolve, $state->context());

            if (count($prices) !== count($toResolve)) {
                throw new LogicException('PriceResolver returned '.count($prices).' prices for '.count($toResolve).' requests.');
            }

            foreach ($resolveIndexes as $pos => $originalIndex) {
                $resolvedByIndex[$originalIndex] = $prices[$pos];
            }
        }

        foreach ($inputs as $index => $input) {
            $sellingPlan = $input->sellingPlan ?? $state->input->sellingPlan;

            if ($input->fixedUnitPrice !== null) {
                $unitPrice = $sellingPlan?->unitPriceForCycle($input->fixedUnitPrice, 1, $state->input->rounding) ?? $input->fixedUnitPrice;
                $state = $state->withLine(new QuoteLine(
                    $input->lineId,
                    $input->sellable,
                    $input->quantity,
                    $unitPrice,
                    $input->sellable->basePrice,
                    $input->taxIncluded ?? false,
                    $input->priceSource ?? 'cart_transform',
                    $input->sellable->unitPrice?->withAmount($unitPrice),
                    $state->input->rounding,
                    isGift: false,
                    sellingPlan: $sellingPlan,
                ));

                continue;
            }

            $price = $resolvedByIndex[$index];
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
