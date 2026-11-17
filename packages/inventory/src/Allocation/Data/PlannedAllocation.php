<?php

declare(strict_types=1);

namespace Commerz\Inventory\Allocation\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Identity\SellableRef;
use Commerz\Inventory\Locations\Data\LocationId;
use Packman\Attributes\Api;

/** `units` of the sellable held in the location. */
#[Api(since: '0.1')]
final readonly class PlannedAllocation
{
    public function __construct(
        public SellableRef $sellable,
        public LocationId $location,
        public int $units,
    ) {
        if ($units < 1) {
            throw new InvalidCommand('units', 'must be at least 1', 'inventory.invalid_allocation');
        }
    }
}
