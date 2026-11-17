<?php

declare(strict_types=1);

namespace Commerz\Ports\Stock\Data;

use Packman\Attributes\Api;

/** Where a reserved quantity is held. */
#[Api(since: '0.1')]
final readonly class StockAllocation
{
    public function __construct(public string $locationCode, public int $quantity) {}
}
