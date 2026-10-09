<?php

declare(strict_types=1);

use Commerz\Identity\SellableRef;
use Commerz\Inventory\Allocation\Data\AllocationNeed;
use Commerz\Inventory\Allocation\Data\AllocationPlan;
use Commerz\Inventory\Allocation\Data\AllocationRequest;
use Commerz\Inventory\Allocation\Data\NeedComponent;
use Commerz\Inventory\Allocation\Data\SourceStock;
use Commerz\Inventory\Items\Data\StockItemId;
use Commerz\Inventory\Locations\Data\LocationId;
use Commerz\Stores\Data\ChannelId;

/** Stable ids for a readable test: `alloc_id('wheel')` is the same uuid every call. */
function alloc_id(string $name): string
{
    $hex = substr(md5($name), 0, 32);

    return sprintf('%s-%s-7%s-8%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 13, 3), substr($hex, 17, 3), substr($hex, 20, 12));
}

function alloc_item(string $name): StockItemId
{
    return new StockItemId(alloc_id("item:{$name}"));
}

function alloc_component(string $item, int $perUnit = 1, bool $backorder = false): NeedComponent
{
    return new NeedComponent(alloc_item($item), $perUnit, $backorder);
}

/** @param  list<NeedComponent>  $components */
function alloc_need(string $sellable, int $units, array $components): AllocationNeed
{
    return new AllocationNeed(SellableRef::of('variant', $sellable), $units, $components);
}

/** @param  array<string, int>  $free  item name => free units */
function alloc_source(string $code, int $priority, array $free): SourceStock
{
    $byId = [];

    foreach ($free as $item => $units) {
        $byId[(string) alloc_item($item)] = $units;
    }

    return new SourceStock(new LocationId(alloc_id("location:{$code}")), $code, $priority, $byId);
}

/**
 * @param  list<AllocationNeed>  $needs
 * @param  list<SourceStock>  $sources
 */
function alloc_request(array $needs, array $sources): AllocationRequest
{
    return new AllocationRequest(ChannelId::generate(), $needs, $sources);
}

/** @return list<string> `sellable@location=units` of each allocation, in plan order */
function alloc_describe(AllocationPlan $plan): array
{
    $location = static fn (string $id): string => array_find(
        ['main', 'east', 'west', 'north'],
        static fn (string $code): bool => alloc_id("location:{$code}") === $id,
    ) ?? $id;

    return array_map(
        static fn ($a): string => $a->sellable.'@'.$location((string) $a->location).'='.$a->units,
        $plan->allocations,
    );
}
