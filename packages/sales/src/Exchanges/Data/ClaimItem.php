<?php

declare(strict_types=1);

namespace Commerz\Sales\Exchanges\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Identity\SellableRef;
use Commerz\Sales\Orders\Data\OrderLineId;
use Packman\Attributes\Api;

/**
 * Identifies an item to ship free of charge in an {@see OrderClaim}, either referencing a fulfilled order line
 * (reshipment) or an explicit replacement {@see SellableRef}.
 */
#[Api(since: '0.1')]
final readonly class ClaimItem
{
    public function __construct(
        public OrderLineId|SellableRef $target,
        public int $quantity = 1,
    ) {
        if ($quantity < 1) {
            throw new InvalidCommand('quantity', 'must be at least 1', 'sales.invalid_quantity');
        }
    }

    public static function orderLine(OrderLineId $lineId, int $quantity = 1): self
    {
        return new self($lineId, $quantity);
    }

    public static function sellable(SellableRef $sellable, int $quantity = 1): self
    {
        return new self($sellable, $quantity);
    }
}
