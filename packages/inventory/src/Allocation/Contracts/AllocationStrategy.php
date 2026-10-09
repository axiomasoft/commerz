<?php

declare(strict_types=1);

namespace Commerz\Inventory\Allocation\Contracts;

use Commerz\Attributes\Spi;
use Commerz\Attributes\SpiKind;
use Commerz\Inventory\Allocation\Data\AllocationNeed;
use Commerz\Inventory\Allocation\Data\AllocationPlan;
use Commerz\Inventory\Allocation\Data\AllocationRequest;
use Commerz\Inventory\Allocation\Data\SourceStock;

/**
 * Proposes where demand is held, contributed under kind `allocation_strategy`. Pure: no I/O, no clock, no state
 * between calls. Inventory validates the plan and owns locking, so a strategy may leave needs unplaced but can
 * never place more than the sources hold.
 */
#[Spi(kind: SpiKind::Implement, since: '0.1')]
interface AllocationStrategy
{
    public const string KIND = 'allocation_strategy';

    public function key(): string;                       // 'priority', 'acme.nearest'

    public function allocate(AllocationRequest $request): AllocationPlan;

    /**
     * Sellable units of the need this strategy could place from the sources; null when stock does not limit it.
     *
     * @param  list<SourceStock>  $sources
     */
    public function capacity(AllocationNeed $need, array $sources): ?int;
}
