<?php

declare(strict_types=1);

namespace Commerz\Sales\Carts\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Identity\SellableRef;
use Packman\Attributes\Api;

/** Adds `$quantity` of a sellable; a cart has one line per sellable, so an existing line grows. */
#[Api(since: '0.1')]
final readonly class AddCartLine
{
    public function __construct(public CartId $cart, public SellableRef $sellable, public int $quantity)
    {
        if ($quantity < 1) {
            throw new InvalidCommand('quantity', 'must be at least 1', 'sales.invalid_quantity');
        }
    }
}
