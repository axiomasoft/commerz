<?php

declare(strict_types=1);

namespace Commerz\Sales\Common\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Sales\Orders\Data\OrderLineId;
use Packman\Attributes\Api;

/** A number of units of one order line: what a cancellation, a fulfillment or a return takes from it. */
#[Api(since: '0.1')]
final readonly class LineQuantity
{
    public function __construct(public OrderLineId $lineId, public int $quantity)
    {
        if ($quantity < 1) {
            throw new InvalidCommand('quantity', 'must be at least 1', 'sales.invalid_quantity');
        }
    }
}
