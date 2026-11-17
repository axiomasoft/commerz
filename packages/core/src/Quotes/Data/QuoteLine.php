<?php

declare(strict_types=1);

namespace Commerz\Quotes\Data;

use Commerz\Money\Money;
use Commerz\Ports\Sellables\Data\SellableSnapshot;
use Packman\Attributes\Api;

/** A priced line inside the pipeline: what the base stage resolved, before adjustments and taxes. */
#[Api(since: '0.1')]
final readonly class QuoteLine
{
    public function __construct(
        public string $lineId,
        public SellableSnapshot $sellable,
        public int $quantity,
        public Money $unitPrice,
        public ?Money $compareAt,
        public bool $taxIncluded,
        public string $priceSource,
    ) {}

    public function subtotal(): Money
    {
        return $this->unitPrice->multiply($this->quantity);
    }
}
