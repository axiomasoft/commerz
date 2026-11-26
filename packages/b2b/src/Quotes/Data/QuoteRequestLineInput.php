<?php

declare(strict_types=1);

namespace Commerz\B2b\Quotes\Data;

use Commerz\B2b\PaymentTerms\Data\SellingPlanSummary;
use Commerz\Errors\InvalidCommand;
use Commerz\Identity\SellableRef;
use Commerz\Money\Money;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class QuoteRequestLineInput
{
    public ?SellingPlanSummary $sellingPlan;

    public function __construct(
        public string $lineId,
        public SellableRef $sellable,
        public int $quantity,
        public ?Money $targetUnitPrice = null,
        public ?string $note = null,
        ?object $sellingPlan = null,
    ) {
        if (preg_match('/\A[A-Za-z0-9_.:\-]{1,64}\z/', $lineId) !== 1) {
            throw new InvalidCommand('lineId', "'{$lineId}' must match [A-Za-z0-9_.:-]{1,64}", 'b2b.invalid_quote_line');
        }

        if ($quantity < 1) {
            throw new InvalidCommand('quantity', 'must be at least 1', 'b2b.invalid_quote_line');
        }

        if ($targetUnitPrice !== null && $targetUnitPrice->isNegative()) {
            throw new InvalidCommand('targetUnitPrice', 'must not be negative', 'b2b.invalid_quote_line');
        }

        $this->sellingPlan = $sellingPlan === null ? null : SellingPlanSummary::from($sellingPlan);
    }
}
