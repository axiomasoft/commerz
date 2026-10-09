<?php

declare(strict_types=1);

namespace Commerz\Inventory\Allocation\Data;

use Commerz\Attributes\Api;
use Commerz\Errors\InvalidCommand;
use Commerz\Inventory\Locations\Data\LocationId;

/** What a location can give a channel right now. */
#[Api(since: '0.1')]
final readonly class SourceStock
{
    /**
     * @param  int  $priority  smaller is earlier
     * @param  array<string, int>  $free  stock item id => units free to reserve (never negative); a missing item has none
     */
    public function __construct(
        public LocationId $location,
        public string $locationCode,
        public int $priority,
        public array $free,
    ) {
        foreach ($free as $item => $units) {
            if ($units < 0) {
                throw new InvalidCommand('free', "item '{$item}' has negative free stock", 'inventory.invalid_allocation');
            }
        }
    }
}
