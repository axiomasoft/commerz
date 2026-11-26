<?php

declare(strict_types=1);

namespace Commerz\B2b\Quotes\Data;

use Commerz\B2b\PaymentTerms\Data\SellingPlanSummary;
use Commerz\Identity\SellableRef;
use Commerz\Money\Money;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class QuoteRequestLineView
{
    public function __construct(
        public string $lineId,
        public SellableRef $sellable,
        public int $quantity,
        public ?Money $targetUnitPrice,
        public ?string $note,
        public ?SellingPlanSummary $sellingPlan,
    ) {}
}
