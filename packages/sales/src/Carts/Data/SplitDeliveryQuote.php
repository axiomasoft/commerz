<?php

declare(strict_types=1);

namespace Commerz\Sales\Carts\Data;

use Commerz\Money\Money;
use Commerz\Quotes\Data\QuoteResult;
use Packman\Attributes\Api;

/** Priced split-delivery breakdown across cart line groups alongside the cart's goods quote. */
#[Api(since: '0.1')]
final readonly class SplitDeliveryQuote
{
    /** @param  list<PricedDeliveryGroup>  $groups */
    public function __construct(
        public QuoteResult $goodsQuote,
        public array $groups,
        public Money $deliveryTotal,
        public Money $grandTotal,
    ) {}
}
