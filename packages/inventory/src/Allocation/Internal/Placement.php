<?php

declare(strict_types=1);

namespace Commerz\Inventory\Allocation\Internal;

use Commerz\Inventory\Allocation\Data\AllocationNeed;
use Commerz\Inventory\Allocation\Data\NeedComponent;
use Commerz\Inventory\Common\Internal\Quantities;
use Packman\Attributes\Internal;

/** Arithmetic shared by the built-in strategies; `$free` is item id => units free in one location. */
#[Internal]
final class Placement
{
    /**
     * Whole sellable units the free stock covers. `$backorderToo` false lets backorder components be ignored (they
     * may be reserved beyond their free stock); null when nothing limits the answer.
     *
     * @param  list<NeedComponent>  $components
     * @param  array<string, int>  $free
     */
    public static function fitting(array $components, array $free, bool $backorderToo): ?int
    {
        $fitting = null;

        foreach ($components as $component) {
            if ($component->backorder && ! $backorderToo) {
                continue;
            }

            $units = intdiv($free[(string) $component->item] ?? 0, $component->quantityPerUnit);
            $fitting = $fitting === null ? $units : min($fitting, $units);
        }

        return $fitting;
    }

    /**
     * Takes `$units` sellable units out of `$free`; the caller never takes more than {@see self::fitting} allows.
     *
     * @param  list<NeedComponent>  $components
     * @param  array<string, int>  $free
     * @return array<string, int>
     */
    public static function take(array $components, array $free, int $units, bool $backorderToo): array
    {
        foreach ($components as $component) {
            if ($component->backorder && ! $backorderToo) {
                continue;
            }

            $item = (string) $component->item;
            $free[$item] = max(0, ($free[$item] ?? 0) - ($units * $component->quantityPerUnit));
        }

        return $free;
    }

    /**
     * Units of the need that stock alone limits: the tracked components decide, backorder ones never do.
     *
     * @param  array<string, int>  $free
     */
    public static function limit(AllocationNeed $need, array $free): ?int
    {
        return self::fitting($need->components, $free, backorderToo: false);
    }

    /** Sum that stops at PHP_INT_MAX instead of overflowing. */
    public static function sum(int $a, int $b): int
    {
        return Quantities::add($a, $b) ?? PHP_INT_MAX;
    }
}
