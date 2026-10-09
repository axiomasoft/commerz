<?php

declare(strict_types=1);

namespace Commerz\Inventory\Allocation\Data;

use Commerz\Attributes\Api;
use Commerz\Errors\InvalidCommand;
use Commerz\Identity\SellableRef;

/** Units of one sellable to place; all components of one unit are placed in the same location. */
#[Api(since: '0.1')]
final readonly class AllocationNeed
{
    /** @param  list<NeedComponent>  $components  one per stock item, never empty */
    public function __construct(
        public SellableRef $sellable,
        public int $units,
        public array $components,
    ) {
        if ($units < 1) {
            throw new InvalidCommand('units', 'must be at least 1', 'inventory.invalid_allocation');
        }

        if ($components === []) {
            throw new InvalidCommand('components', 'must not be empty', 'inventory.invalid_allocation');
        }

        $seen = [];

        foreach ($components as $component) {
            $key = (string) $component->item;

            if (isset($seen[$key])) {
                throw new InvalidCommand('components', "item '{$key}' appears more than once", 'inventory.invalid_allocation');
            }

            $seen[$key] = true;
        }
    }
}
