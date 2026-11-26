<?php

declare(strict_types=1);

namespace Commerz\B2b\Quotes\Internal;

use Commerz\Quotes\Contracts\QuoteStage;
use Commerz\Quotes\Data\QuoteLine;
use Commerz\Quotes\Data\QuoteState;
use Commerz\Quotes\Enums\QuoteAnchor;
use Commerz\Quotes\Enums\StagePosition;
use Override;
use Packman\Attributes\Internal;

/**
 * Quote stage running immediately after `Base` that replaces catalog unit prices with locked B2B negotiated unit prices
 * whenever a `LockedPriceScope` is active.
 */
#[Internal]
final readonly class LockedQuotePriceStage implements QuoteStage
{
    public const string KEY = 'b2b.locked_prices';

    public function __construct(private LockedPriceScope $scope) {}

    #[Override]
    public function key(): string
    {
        return self::KEY;
    }

    #[Override]
    public function anchor(): QuoteAnchor
    {
        return QuoteAnchor::Base;
    }

    #[Override]
    public function position(): StagePosition
    {
        return StagePosition::After;
    }

    #[Override]
    public function apply(QuoteState $state): QuoteState
    {
        if (! $this->scope->isActive()) {
            return $state;
        }

        $source = $this->scope->priceSource();

        foreach ($state->lines as $line) {
            if ($line->isGift) {
                continue;
            }

            $locked = $this->scope->resolveUnitPrice($line->lineId, (string) $line->sellable->ref);

            if ($locked === null) {
                continue;
            }

            $state = $state->withLine(new QuoteLine(
                lineId: $line->lineId,
                sellable: $line->sellable,
                quantity: $line->quantity,
                unitPrice: $locked,
                compareAt: $line->unitPrice->compare($locked) > 0 ? $line->unitPrice : $line->compareAt,
                taxIncluded: $line->taxIncluded,
                priceSource: $source,
                pricePerUnit: $line->sellable->unitPrice?->withAmount($locked),
                rounding: $line->rounding,
            ));
        }

        return $state;
    }
}
