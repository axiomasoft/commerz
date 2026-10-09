<?php

declare(strict_types=1);

use Commerz\Errors\InvalidCommand;
use Commerz\Identity\SellableRef;
use Commerz\Inventory\Allocation\Data\AllocationNeed;
use Commerz\Inventory\Allocation\Data\AllocationRequest;
use Commerz\Inventory\Allocation\Data\NeedComponent;
use Commerz\Inventory\Allocation\Data\PlannedAllocation;
use Commerz\Inventory\Allocation\Data\SourceStock;
use Commerz\Inventory\Locations\Data\LocationId;
use Commerz\Stores\Data\ChannelId;

require_once __DIR__.'/Fixtures.php';

it('rejects malformed allocation data', function (callable $build): void {
    expect($build)->toThrow(fn (InvalidCommand $e) => expect($e->code())->toBe('inventory.invalid_allocation'));
})->with([
    'component quantity' => [fn () => new NeedComponent(alloc_item('a'), 0)],
    'need units' => [fn () => new AllocationNeed(SellableRef::of('variant', 'a'), 0, [alloc_component('a')])],
    'need without components' => [fn () => new AllocationNeed(SellableRef::of('variant', 'a'), 1, [])],
    'need with a repeated item' => [fn () => alloc_need('a', 1, [alloc_component('a'), alloc_component('a', 2)])],
    'negative free stock' => [fn () => new SourceStock(LocationId::generate(), 'main', 1, ['x' => -1])],
    'request without needs' => [fn () => new AllocationRequest(ChannelId::generate(), [], [])],
    'request with a repeated sellable' => [fn () => alloc_request([alloc_need('a', 1, [alloc_component('a')]), alloc_need('a', 2, [alloc_component('a')])], [])],
    'request with a repeated location' => [fn () => alloc_request([alloc_need('a', 1, [alloc_component('a')])], [alloc_source('main', 1, []), alloc_source('main', 2, [])])],
    'planned units' => [fn () => new PlannedAllocation(SellableRef::of('variant', 'a'), LocationId::generate(), 0)],
]);
