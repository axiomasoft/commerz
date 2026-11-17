<?php

declare(strict_types=1);

namespace Commerz\Sales\Carts\Data;

use Commerz\Errors\InvalidCommand;
use Packman\Attributes\Api;

/** Sets the quantity of a line; 0 removes the line. */
#[Api(since: '0.1')]
final readonly class SetCartLineQuantity
{
    public function __construct(public CartId $cart, public string $lineId, public int $quantity)
    {
        if ($quantity < 0) {
            throw new InvalidCommand('quantity', 'must not be negative', 'sales.invalid_quantity');
        }
    }
}
