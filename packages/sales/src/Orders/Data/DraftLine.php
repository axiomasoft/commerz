<?php

declare(strict_types=1);

namespace Commerz\Sales\Orders\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Identity\SellableRef;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class DraftLine
{
    public function __construct(public SellableRef $sellable, public int $quantity)
    {
        if ($quantity < 1) {
            throw new InvalidCommand('quantity', 'must be at least 1', 'sales.invalid_quantity');
        }
    }
}
