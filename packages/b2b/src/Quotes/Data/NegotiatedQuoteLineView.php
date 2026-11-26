<?php

declare(strict_types=1);

namespace Commerz\B2b\Quotes\Data;

use Commerz\B2b\PaymentTerms\Data\SellingPlanSummary;
use Commerz\Money\Money;
use Commerz\Ports\Sellables\Data\SellableSnapshot;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class NegotiatedQuoteLineView
{
    public function __construct(
        public string $lineId,
        public SellableSnapshot $sellable,
        public int $quantity,
        public Money $catalogUnitPrice,
        public Money $lockedUnitPrice,
        public Money $subtotal,
        public Money $taxTotal,
        public Money $total,
        public bool $taxIncluded,
        public ?SellingPlanSummary $sellingPlan = null,
    ) {}
}
