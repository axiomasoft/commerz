<?php

declare(strict_types=1);

namespace Commerz\Ports\Stock\Data;

use Commerz\Attributes\Api;
use Commerz\Errors\InvalidCommand;
use Commerz\Identity\SellableRef;

#[Api(since: '0.1')]
final readonly class AvailabilityLine
{
    public function __construct(public SellableRef $sellable, public int $quantity)
    {
        if ($quantity < 1) {
            throw new InvalidCommand('quantity', 'must be at least 1', 'stock.invalid_quantity');
        }
    }
}
