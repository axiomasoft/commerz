<?php

declare(strict_types=1);

namespace Commerz\Quotes\Data;

use Commerz\Attributes\Api;
use Commerz\Errors\InvalidCommand;
use Commerz\Money\Money;
use Commerz\Money\Rate;
use Commerz\Quotes\Enums\AdjustmentTarget;

/** Tax on a line or on delivery. An included tax is part of the price; an excluded one is added to the total. */
#[Api(since: '0.1')]
final readonly class TaxLine
{
    /** @param  string  $code  tax identifier, e.g. `vat` */
    public function __construct(
        public AdjustmentTarget $target,
        public ?string $lineId,
        public string $code,
        public Rate $rate,
        public Money $amount,
        public bool $included,
    ) {
        if ($target === AdjustmentTarget::Order) {
            throw new InvalidCommand('target', 'tax applies to a line or to delivery', 'quotes.invalid_tax');
        }

        if (($target === AdjustmentTarget::Line) !== ($lineId !== null)) {
            throw new InvalidCommand('lineId', 'a line tax needs a line id, a delivery tax has none', 'quotes.invalid_tax');
        }

        if ($amount->isNegative()) {
            throw new InvalidCommand('amount', 'must not be negative', 'quotes.invalid_tax');
        }

        if ($code === '') {
            throw new InvalidCommand('code', 'must not be empty', 'quotes.invalid_tax');
        }
    }
}
