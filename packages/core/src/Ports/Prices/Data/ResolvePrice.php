<?php

declare(strict_types=1);

namespace Commerz\Ports\Prices\Data;

use Commerz\Attributes\Api;
use Commerz\Errors\InvalidCommand;
use Commerz\Identity\SellableRef;
use Commerz\Money\Money;

#[Api(since: '0.1')]
final readonly class ResolvePrice
{
    /** @param  Money|null  $fallback  the sellable's own base price, used when no price list has one */
    public function __construct(public SellableRef $sellable, public int $quantity, public ?Money $fallback = null)
    {
        if ($quantity < 1) {
            throw new InvalidCommand('quantity', 'must be at least 1', 'prices.invalid_quantity');
        }
    }
}
