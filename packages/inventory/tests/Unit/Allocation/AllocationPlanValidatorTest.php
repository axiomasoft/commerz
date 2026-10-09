<?php

declare(strict_types=1);

use Commerz\Identity\SellableRef;
use Commerz\Inventory\Allocation\Data\AllocationPlan;
use Commerz\Inventory\Allocation\Data\PlannedAllocation;
use Commerz\Inventory\Allocation\Exceptions\InvalidAllocationPlan;
use Commerz\Inventory\Allocation\Internal\AllocationPlanValidator;
use Commerz\Inventory\Allocation\Internal\PriorityAllocation;
use Commerz\Inventory\Locations\Data\LocationId;

require_once __DIR__.'/Fixtures.php';

function planned(string $sellable, string $location, int $units): PlannedAllocation
{
    return new PlannedAllocation(SellableRef::of('variant', $sellable), new LocationId(alloc_id("location:{$location}")), $units);
}

function validated(array $allocations, ?array $needs = null, ?array $sources = null): void
{
    $request = alloc_request(
        $needs ?? [alloc_need('bike', 3, [alloc_component('wheel', 2), alloc_component('frame')])],
        $sources ?? [alloc_source('main', 1, ['wheel' => 5, 'frame' => 5]), alloc_source('east', 2, ['wheel' => 4, 'frame' => 1])],
    );

    new AllocationPlanValidator()->validate($request, new AllocationPlan($allocations));
}

it('accepts a plan within needs, sources and free stock', function (): void {
    validated([planned('bike', 'main', 2), planned('bike', 'east', 1)]);
    validated([]);

    $request = alloc_request(
        [alloc_need('a', 10, [alloc_component('a')]), alloc_need('b', 2, [alloc_component('a', 3)])],
        [alloc_source('main', 1, ['a' => 6]), alloc_source('east', 2, ['a' => 7])],
    );
    new AllocationPlanValidator()->validate($request, new PriorityAllocation()->allocate($request));

    expect(true)->toBeTrue();
});

it('rejects a plan the sources or needs do not allow', function (callable $plan, string $reason): void {
    expect($plan)->toThrow(InvalidAllocationPlan::class, $reason);
})->with([
    'unknown sellable' => [fn () => validated([planned('car', 'main', 1)]), "'variant:car' was not asked for"],
    'unknown location' => [fn () => validated([planned('bike', 'north', 1)]), 'is not a source'],
    'more units than asked' => [fn () => validated([planned('bike', 'main', 2), planned('bike', 'east', 2)]), 'but 3 were asked for'],
    'more than free (single line)' => [fn () => validated([planned('bike', 'east', 2)]), 'has 1 free of item'],
    'more than free (across lines)' => [fn () => validated([planned('bike', 'main', 2), planned('bike', 'main', 1)]), 'has 5 free of item'],
    'shared item overdrawn by two needs' => [fn () => validated(
        [planned('x', 'main', 3), planned('y', 'main', 3)],
        [alloc_need('x', 3, [alloc_component('a')]), alloc_need('y', 3, [alloc_component('a')])],
        [alloc_source('main', 1, ['a' => 5])],
    ), 'has 5 free of item'],
    'int64 overflow of stock units' => [fn () => validated(
        [planned('x', 'main', 2)],
        [alloc_need('x', 2, [alloc_component('a', PHP_INT_MAX)])],
        [alloc_source('main', 1, ['a' => PHP_INT_MAX])],
    ), 'takes more'],
    'int64 overflow of placed units' => [fn () => validated(
        [planned('x', 'main', PHP_INT_MAX), planned('x', 'east', 1)],
        [alloc_need('x', PHP_INT_MAX, [alloc_component('a')])],
        [alloc_source('main', 1, ['a' => PHP_INT_MAX]), alloc_source('east', 2, ['a' => 1])],
    ), 'overflows int64'],
]);

it('lets backorder components go beyond free stock but not tracked ones', function (): void {
    $needs = [alloc_need('x', 5, [alloc_component('b', 1, backorder: true)])];
    $sources = [alloc_source('main', 1, ['b' => 0])];

    validated([planned('x', 'main', 5)], $needs, $sources);

    $kit = [alloc_need('kit', 5, [alloc_component('a'), alloc_component('b', 1, backorder: true)])];

    expect(fn () => validated([planned('kit', 'main', 5)], $kit, [alloc_source('main', 1, ['a' => 2])]))
        ->toThrow(InvalidAllocationPlan::class, "free of item '".alloc_item('a')."'");
});

it('reports an integration error with a stable code', function (): void {
    expect(fn () => validated([planned('car', 'main', 1)]))->toThrow(function (InvalidAllocationPlan $e): void {
        expect($e->code())->toBe('inventory.invalid_allocation_plan')
            ->and($e->category()->value)->toBe('integration')
            ->and($e->context())->toHaveKey('reason');
    });
});
