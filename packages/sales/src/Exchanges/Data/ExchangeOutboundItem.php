<?php

declare(strict_types=1);

namespace Commerz\Sales\Exchanges\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Identity\SellableRef;
use Commerz\Money\Money;
use Packman\Attributes\Api;

/**
 * One outbound replacement item requested in an {@see OrderExchange}.
 * When `$fixedUnitPrice` is null, the item is priced through the quote pipeline in the order's context.
 */
#[Api(since: '0.1')]
final readonly class ExchangeOutboundItem
{
    public function __construct(
        public SellableRef $sellable,
        public int $quantity = 1,
        public ?Money $fixedUnitPrice = null,
        public ?bool $taxIncluded = null,
    ) {
        if ($quantity < 1) {
            throw new InvalidCommand('quantity', 'must be at least 1', 'sales.invalid_quantity');
        }

        if ($fixedUnitPrice !== null && $fixedUnitPrice->isNegative()) {
            throw new InvalidCommand('fixedUnitPrice', 'must not be negative', 'sales.invalid_exchange');
        }
    }
}
