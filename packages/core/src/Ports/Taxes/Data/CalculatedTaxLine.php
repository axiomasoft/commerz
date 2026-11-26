<?php

declare(strict_types=1);

namespace Commerz\Ports\Taxes\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Money\Money;
use Commerz\Money\Rate;
use Commerz\Quotes\Enums\AdjustmentTarget;
use Packman\Attributes\Api;

/** One computed tax component returned by `TaxEngine`. */
#[Api(since: '0.1')]
final readonly class CalculatedTaxLine
{
    public function __construct(
        public AdjustmentTarget $target,
        public ?string $lineId,
        public string $code,
        public Rate $rate,
        public Money $amount,
        public bool $included,
    ) {
        match ($target) {
            AdjustmentTarget::Line => $lineId !== null && $lineId !== ''
                ? null
                : throw new InvalidCommand('lineId', 'a line tax needs a line id', 'taxes.invalid_tax_line'),
            AdjustmentTarget::Delivery => $lineId === null
                ? null
                : throw new InvalidCommand('lineId', 'a delivery tax has no line id', 'taxes.invalid_tax_line'),
            AdjustmentTarget::Order => throw new InvalidCommand('target', 'tax applies to a line or to delivery', 'taxes.invalid_tax_line'),
        };

        if (preg_match('/\A[a-z0-9_]{1,32}\z/', $code) !== 1) {
            throw new InvalidCommand('code', "'{$code}' must match [a-z0-9_]{1,32}", 'taxes.invalid_tax_line');
        }

        if ($amount->isNegative()) {
            throw new InvalidCommand('amount', 'must not be negative', 'taxes.invalid_tax_line');
        }
    }

    public function itemKey(): string
    {
        return $this->target->value.'|'.($this->lineId ?? '');
    }
}
