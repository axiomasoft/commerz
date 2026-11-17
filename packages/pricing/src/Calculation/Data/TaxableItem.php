<?php

declare(strict_types=1);

namespace Commerz\Pricing\Calculation\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Identity\SellableRef;
use Commerz\Money\Money;
use Commerz\Pricing\Common\Data\PricingCodes;
use Commerz\Quotes\Enums\AdjustmentTarget;
use Packman\Attributes\Api;

/** One taxable amount of a quote: a line or the delivery charge, after all discounts. */
#[Api(since: '0.1')]
final readonly class TaxableItem
{
    /**
     * @param  string|null  $lineId  the quote line; null for delivery
     * @param  string  $taxClass  tax class code
     * @param  Money  $base  what tax is computed on: the net after line adjustments and the line's share of order adjustments
     * @param  bool  $included  the tax is already part of $base
     */
    public function __construct(
        public AdjustmentTarget $target,
        public ?string $lineId,
        public ?SellableRef $sellable,
        public string $taxClass,
        public int $quantity,
        public Money $base,
        public bool $included,
    ) {
        match ($target) {
            AdjustmentTarget::Line => $lineId !== null && $lineId !== ''
                ? null
                : throw new InvalidCommand('lineId', 'a line item needs a line id', 'pricing.invalid_tax_item'),
            AdjustmentTarget::Delivery => $lineId === null && $sellable === null
                ? null
                : throw new InvalidCommand('lineId', 'a delivery item has neither a line id nor a sellable', 'pricing.invalid_tax_item'),
            AdjustmentTarget::Order => throw new InvalidCommand('target', 'tax applies to a line or to delivery', 'pricing.invalid_tax_item'),
        };

        PricingCodes::code('taxClass', $taxClass);

        if ($quantity < 1) {
            throw new InvalidCommand('quantity', 'must be at least 1', 'pricing.invalid_tax_item');
        }

        if ($base->isNegative()) {
            throw new InvalidCommand('base', 'must not be negative', 'pricing.invalid_tax_item');
        }
    }

    /** Key that matches an item with the amounts computed for it. */
    public function key(): string
    {
        return $this->target->value.'|'.($this->lineId ?? '');
    }
}
