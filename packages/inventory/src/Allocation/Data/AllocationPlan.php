<?php

declare(strict_types=1);

namespace Commerz\Inventory\Allocation\Data;

use Packman\Attributes\Api;

/** A strategy's proposal; may place less than asked. Inventory validates it before it holds anything. */
#[Api(since: '0.1')]
final readonly class AllocationPlan
{
    /** @param  list<PlannedAllocation>  $allocations */
    public function __construct(public array $allocations = []) {}
}
