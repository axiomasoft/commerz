<?php

declare(strict_types=1);

namespace Commerz\Pricing\Calculation\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Money\Money;
use Commerz\Money\Rate;
use Commerz\Pricing\Common\Data\PricingCodes;
use Commerz\Quotes\Enums\AdjustmentTarget;
use Packman\Attributes\Api;

/** One tax computed for a requested item; the item's own `included` flag says whether it is part of the price. */
#[Api(since: '0.1')]
final readonly class TaxAmount
{
    /** @param  string  $code  tax identifier, e.g. `vat` */
    public function __construct(
        public AdjustmentTarget $target,
        public ?string $lineId,
        public string $code,
        public Rate $rate,
        public Money $amount,
    ) {
        match ($target) {
            AdjustmentTarget::Line => $lineId !== null && $lineId !== ''
                ? null
                : throw new InvalidCommand('lineId', 'a line tax needs a line id', 'pricing.invalid_tax_amount'),
            AdjustmentTarget::Delivery => $lineId === null
                ? null
                : throw new InvalidCommand('lineId', 'a delivery tax has no line id', 'pricing.invalid_tax_amount'),
            AdjustmentTarget::Order => throw new InvalidCommand('target', 'tax applies to a line or to delivery', 'pricing.invalid_tax_amount'),
        };

        PricingCodes::taxCode('code', $code);

        if ($amount->isNegative()) {
            throw new InvalidCommand('amount', 'must not be negative', 'pricing.invalid_tax_amount');
        }
    }

    /** Key of the item this tax belongs to; the same as `TaxableItem::key()`. */
    public function itemKey(): string
    {
        return $this->target->value.'|'.($this->lineId ?? '');
    }
}
