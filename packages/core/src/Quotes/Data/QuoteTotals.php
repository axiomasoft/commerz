<?php

declare(strict_types=1);

namespace Commerz\Quotes\Data;

use Commerz\Money\Money;
use Packman\Attributes\Api;

/**
 * `total = subtotal − discountTotal + deliveryTotal + excluded taxes`. `discountTotal` (positive) sums line and
 * order adjustments; `deliveryTotal` is the delivery price after delivery adjustments; `taxTotal` sums all taxes,
 * included and excluded.
 */
#[Api(since: '0.1')]
final readonly class QuoteTotals
{
    public function __construct(
        public Money $subtotal,
        public Money $discountTotal,
        public Money $deliveryTotal,
        public Money $taxTotal,
        public Money $total,
    ) {}
}
