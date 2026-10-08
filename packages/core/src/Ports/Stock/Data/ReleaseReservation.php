<?php

declare(strict_types=1);

namespace Commerz\Ports\Stock\Data;

use Commerz\Attributes\Api;
use Commerz\Errors\InvalidCommand;
use Commerz\Identity\SellableRef;

/** Releases the whole demand, all of one sellable, or `$quantity` of one sellable. */
#[Api(since: '0.1')]
final readonly class ReleaseReservation
{
    public function __construct(public string $demand, public ?SellableRef $sellable = null, public ?int $quantity = null)
    {
        StockLines::assertDemand($demand);

        if ($quantity !== null && ($sellable === null || $quantity < 1)) {
            throw new InvalidCommand('quantity', 'needs a sellable and must be at least 1', 'stock.invalid_quantity');
        }
    }
}
