<?php

declare(strict_types=1);

namespace Commerz\Inventory\Allocation\Internal;

use Commerz\Inventory\Allocation\Data\AllocationNeed;
use Commerz\Inventory\Allocation\Data\AllocationPlan;
use Commerz\Inventory\Allocation\Data\AllocationRequest;
use Commerz\Inventory\Allocation\Exceptions\InvalidAllocationPlan;
use Commerz\Inventory\Common\Internal\Quantities;

/** Does not trust a strategy: a plan must stay within the request's needs, sources and free stock. */
final class AllocationPlanValidator
{
    /** @throws InvalidAllocationPlan */
    public function validate(AllocationRequest $request, AllocationPlan $plan): void
    {
        /** @var array<string, AllocationNeed> $needs */
        $needs = [];

        foreach ($request->needs as $need) {
            $needs[(string) $need->sellable] = $need;
        }

        $sources = [];

        foreach ($request->sources as $source) {
            $sources[(string) $source->location] = $source;
        }

        /** @var array<string, int> $placed sellable => units */
        $placed = [];
        /** @var array<string, array<string, int>> $used location => item => stock units */
        $used = [];

        foreach ($plan->allocations as $allocation) {
            $sellable = (string) $allocation->sellable;
            $location = (string) $allocation->location;

            if (! isset($needs[$sellable])) {
                throw new InvalidAllocationPlan("'{$sellable}' was not asked for");
            }

            if (! isset($sources[$location])) {
                throw new InvalidAllocationPlan("location '{$location}' is not a source of the channel");
            }

            if ($allocation->units < 1) {
                throw new InvalidAllocationPlan("'{$sellable}' is placed with {$allocation->units} units");
            }

            $placed[$sellable] = Quantities::add($placed[$sellable] ?? 0, $allocation->units)
                ?? throw new InvalidAllocationPlan("'{$sellable}' overflows int64");

            if ($placed[$sellable] > $needs[$sellable]->units) {
                throw new InvalidAllocationPlan("'{$sellable}' is placed with {$placed[$sellable]} units but {$needs[$sellable]->units} were asked for");
            }

            foreach ($needs[$sellable]->components as $component) {
                if ($component->backorder) {
                    continue;
                }

                $item = (string) $component->item;
                $stock = Quantities::mul($allocation->units, $component->quantityPerUnit);
                $total = $stock === null ? null : Quantities::add($used[$location][$item] ?? 0, $stock);
                $free = $sources[$location]->free[$item] ?? 0;

                if ($total === null || $total > $free) {
                    throw new InvalidAllocationPlan("location '{$location}' has {$free} free of item '{$item}', the plan takes more");
                }

                $used[$location][$item] = $total;
            }
        }
    }
}
