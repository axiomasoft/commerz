<?php

declare(strict_types=1);

namespace Commerz\Ports\Stock\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Identity\SellableRef;
use Packman\Attributes\Api;

/**
 * Units of a returned sellable recorded as a damaged write-off (`INV-INV-002`: never increases sellable `on_hand`).
 */
#[Api(since: '0.1')]
final readonly class WriteOffLine
{
    public function __construct(
        public SellableRef $sellable,
        public int $quantity,
    ) {
        if ($quantity < 1) {
            throw new InvalidCommand('quantity', 'must be at least 1', 'stock.invalid_quantity');
        }
    }
}
