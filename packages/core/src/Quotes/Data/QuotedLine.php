<?php

declare(strict_types=1);

namespace Commerz\Quotes\Data;

use Commerz\Measures\UnitPrice;
use Commerz\Money\Money;
use Commerz\Ports\Sellables\Data\SellableSnapshot;
use Packman\Attributes\Api;

/** A settled line of a quote result: what an order line snapshots. */
#[Api(since: '0.1')]
final readonly class QuotedLine
{
    /**
     * @param  Money  $subtotal  unit price × quantity (or UnitPrice::calculate when measured)
     * @param  list<Adjustment>  $adjustments  own line adjustments, then allocated shares of order-level ones
     * @param  list<TaxLine>  $taxLines
     * @param  Money  $total  subtotal + adjustments + excluded taxes
     */
    public function __construct(
        public string $lineId,
        public SellableSnapshot $sellable,
        public int $quantity,
        public Money $unitPrice,
        public ?Money $compareAt,
        public bool $taxIncluded,
        public string $priceSource,
        public Money $subtotal,
        public array $adjustments,
        public array $taxLines,
        public Money $total,
        public ?UnitPrice $pricePerUnit = null,
    ) {}
}
