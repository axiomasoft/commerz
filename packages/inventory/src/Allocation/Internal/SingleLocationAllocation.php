<?php

declare(strict_types=1);

namespace Commerz\Inventory\Allocation\Internal;

use Commerz\Inventory\Allocation\Contracts\AllocationStrategy;
use Commerz\Inventory\Allocation\Data\AllocationNeed;
use Commerz\Inventory\Allocation\Data\AllocationPlan;
use Commerz\Inventory\Allocation\Data\AllocationRequest;
use Commerz\Inventory\Allocation\Data\PlannedAllocation;

/**
 * Holds every need of the request in one location: the first source that has all of it in stock, else the first
 * whose tracked stock suffices and backorder components cover the rest. No such location leaves the plan empty.
 */
final readonly class SingleLocationAllocation implements AllocationStrategy
{
    public const string KEY = 'single_location';

    public function key(): string
    {
        return self::KEY;
    }

    public function allocate(AllocationRequest $request): AllocationPlan
    {
        foreach ([true, false] as $backorderToo) {
            foreach ($request->sources as $source) {
                $free = $source->free;
                $allocations = [];

                foreach ($request->needs as $need) {
                    $fitting = Placement::fitting($need->components, $free, $backorderToo);

                    if ($fitting !== null && $fitting < $need->units) {
                        continue 2;
                    }

                    $free = Placement::take($need->components, $free, $need->units, $backorderToo);
                    $allocations[] = new PlannedAllocation($need->sellable, $source->location, $need->units);
                }

                return new AllocationPlan($allocations);
            }
        }

        return new AllocationPlan;
    }

    public function capacity(AllocationNeed $need, array $sources): ?int
    {
        if (array_all($need->components, static fn ($component): bool => $component->backorder)) {
            return null;
        }

        return array_reduce(
            $sources,
            static fn (int $best, $source): int => max($best, Placement::limit($need, $source->free) ?? 0),
            0,
        );
    }
}
