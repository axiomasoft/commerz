<?php

declare(strict_types=1);

namespace Commerz\Inventory\Allocation\Internal;

use Commerz\Inventory\Allocation\Contracts\AllocationStrategy;
use Commerz\Inventory\Allocation\Data\AllocationNeed;
use Commerz\Inventory\Allocation\Data\AllocationPlan;
use Commerz\Inventory\Allocation\Data\AllocationRequest;
use Commerz\Inventory\Allocation\Data\PlannedAllocation;

/**
 * Fills the channel's sources in order, splitting a need across locations when none holds it all. Units a
 * backorder component cannot cover from free stock go to the first locations whose tracked components still can.
 * A need that does not fit completely is left out of the plan: a reservation is all or nothing.
 */
final readonly class PriorityAllocation implements AllocationStrategy
{
    public const string KEY = 'priority';

    public function key(): string
    {
        return self::KEY;
    }

    public function allocate(AllocationRequest $request): AllocationPlan
    {
        $free = [];

        foreach ($request->sources as $source) {
            $free[(string) $source->location] = $source->free;
        }

        $allocations = [];

        foreach ($request->needs as $need) {
            $attempt = $free;
            $placed = [];
            $remaining = $need->units;

            foreach ([true, false] as $backorderToo) {
                if ($remaining > 0 && ! $backorderToo && ! array_any($need->components, static fn ($component): bool => $component->backorder)) {
                    break;
                }

                foreach ($request->sources as $source) {
                    if ($remaining === 0) {
                        break;
                    }

                    $key = (string) $source->location;
                    $fitting = Placement::fitting($need->components, $attempt[$key], $backorderToo);
                    $units = $fitting === null ? $remaining : min($fitting, $remaining);

                    if ($units < 1) {
                        continue;
                    }

                    $placed[$key] = ($placed[$key] ?? 0) + $units;
                    $attempt[$key] = Placement::take($need->components, $attempt[$key], $units, $backorderToo);
                    $remaining -= $units;
                }
            }

            if ($remaining > 0) {
                continue;
            }

            $free = $attempt;

            foreach ($request->sources as $source) {
                if (isset($placed[(string) $source->location])) {
                    $allocations[] = new PlannedAllocation($need->sellable, $source->location, $placed[(string) $source->location]);
                }
            }
        }

        return new AllocationPlan($allocations);
    }

    public function capacity(AllocationNeed $need, array $sources): ?int
    {
        if (array_all($need->components, static fn ($component): bool => $component->backorder)) {
            return null;
        }

        $capacity = 0;

        foreach ($sources as $source) {
            $capacity = Placement::sum($capacity, Placement::limit($need, $source->free) ?? 0);
        }

        return $capacity;
    }
}
