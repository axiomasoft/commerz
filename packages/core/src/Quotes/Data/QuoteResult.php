<?php

declare(strict_types=1);

namespace Commerz\Quotes\Data;

use Commerz\Attributes\Api;
use Commerz\Context\CommerceContext;
use Commerz\Money\Money;

#[Api(since: '0.1')]
final readonly class QuoteResult
{
    /** Same as `$totals->total`. */
    public Money $total;

    /**
     * @param  list<QuotedLine>  $lines  in input order
     * @param  list<Adjustment>  $orderAdjustments  order-level adjustments as declared (their shares are on the lines)
     * @param  list<Adjustment>  $deliveryAdjustments
     * @param  list<TaxLine>  $taxLines  all taxes, line and delivery
     * @param  list<Rejection>  $rejections
     * @param  list<string>  $codes  the presented codes
     * @param  string  $fingerprint  sha256 of the input and the keys and versions of the stages that ran
     */
    public function __construct(
        public CommerceContext $context,
        public array $lines,
        public array $orderAdjustments,
        public ?DeliveryCharge $delivery,
        public array $deliveryAdjustments,
        public array $taxLines,
        public QuoteTotals $totals,
        public array $rejections,
        public array $codes,
        public string $fingerprint,
    ) {
        $this->total = $totals->total;
    }
}
