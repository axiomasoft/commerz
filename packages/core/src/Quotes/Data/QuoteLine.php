<?php

declare(strict_types=1);

namespace Commerz\Quotes\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Measures\UnitPrice;
use Commerz\Money\Money;
use Commerz\Money\RoundingMode;
use Commerz\Ports\Sellables\Data\SellableSnapshot;
use Packman\Attributes\Api;

/** A priced line inside the pipeline: what the base stage resolved, before adjustments and taxes. */
#[Api(since: '0.1')]
final readonly class QuoteLine
{
    public ?UnitPrice $pricePerUnit;

    public function __construct(
        public string $lineId,
        public SellableSnapshot $sellable,
        public int $quantity,
        public Money $unitPrice,
        public ?Money $compareAt,
        public bool $taxIncluded,
        public string $priceSource,
        ?UnitPrice $pricePerUnit = null,
        public RoundingMode $rounding = RoundingMode::HalfUp,
        public bool $isGift = false,
        public ?string $giftPromotionId = null,
    ) {
        if ($quantity < 1) {
            throw new InvalidCommand('quantity', 'must be at least 1', 'quotes.invalid_line');
        }

        $sellable->quantityStepPolicy?->assertValid($quantity);

        $resolvedPricePerUnit = $pricePerUnit ?? $sellable->unitPrice?->withAmount($unitPrice);

        if ($resolvedPricePerUnit !== null && ! $resolvedPricePerUnit->amount->equals($unitPrice)) {
            throw new InvalidCommand('pricePerUnit', 'amount must equal unitPrice', 'quotes.invalid_line');
        }

        if ($resolvedPricePerUnit !== null && $sellable->quantityStepPolicy !== null && $resolvedPricePerUnit->unit !== $sellable->quantityStepPolicy->unit) {
            throw new InvalidCommand(
                'pricePerUnit',
                "unit '{$resolvedPricePerUnit->unit}' must match quantityStepPolicy unit '{$sellable->quantityStepPolicy->unit}'",
                'quotes.invalid_line',
            );
        }

        $this->pricePerUnit = $resolvedPricePerUnit;
    }

    public function subtotal(): Money
    {
        if ($this->pricePerUnit !== null) {
            return $this->pricePerUnit->calculate($this->quantity, $this->rounding);
        }

        return $this->unitPrice->multiply($this->quantity);
    }
}
