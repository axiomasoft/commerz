<?php

declare(strict_types=1);

namespace Commerz\Inventory\Allocation\Data;

use Commerz\Identity\SellableRef;
use Commerz\Ports\Delivery\Data\FulfillmentAssignment;
use Packman\Attributes\Api;

/**
 * Outcome of order routing: the validated allocation plan, per-location warehouse assignments,
 * any unplaced sellables, and the ordered keys of routing rules applied.
 */
#[Api(since: '0.1')]
final readonly class OrderRoutingResult
{
    /**
     * @param  list<FulfillmentAssignment>  $assignments
     * @param  list<SellableRef>  $unplaced
     * @param  list<string>  $appliedRules
     */
    public function __construct(
        public AllocationPlan $plan,
        public array $assignments,
        public array $unplaced = [],
        public array $appliedRules = [],
    ) {}

    public function isComplete(): bool
    {
        return $this->unplaced === [];
    }

    public function isSplit(): bool
    {
        return count($this->assignments) > 1;
    }

    public function locationCount(): int
    {
        return count($this->assignments);
    }
}
