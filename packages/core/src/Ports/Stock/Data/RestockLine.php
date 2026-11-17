<?php

declare(strict_types=1);

namespace Commerz\Ports\Stock\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Identity\SellableRef;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class RestockLine
{
    /** @param  int  $quantity  units of the sellable put back on hand */
    public function __construct(
        public SellableRef $sellable,
        public int $quantity,
    ) {
        if ($quantity < 1) {
            throw new InvalidCommand('quantity', 'must be at least 1', 'stock.invalid_quantity');
        }
    }
}
