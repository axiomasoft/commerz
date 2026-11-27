<?php

declare(strict_types=1);

use Commerz\Inventory\Allocation\Data\AllocationNeed;
use Commerz\Inventory\Allocation\Data\SourceStock;
use Commerz\Inventory\Allocation\Internal\SingleLocationAllocation;

require_once __DIR__.'/Fixtures.php';

/**
 * @param  list<AllocationNeed>  $needs
 * @param  list<SourceStock>  $sources
 * @return list<string>
 */
function single(array $needs, array $sources): array
{
    return alloc_describe(new SingleLocationAllocation()->allocate(alloc_request($needs, $sources)));
}

it('has the key single_location', function (): void {
    expect(new SingleLocationAllocation()->key())->toBe('single_location');
});

it('holds every need in the first location that has all of it', function (): void {
    $needs = [alloc_need('a', 2, [alloc_component('a')]), alloc_need('b', 1, [alloc_component('b')])];

    $plan = single($needs, [
        alloc_source('main', 1, ['a' => 5]),
        alloc_source('east', 2, ['a' => 1, 'b' => 9]),
        alloc_source('west', 3, ['a' => 9, 'b' => 9]),
    ]);

    expect($plan)->toBe(['variant:a@west=2', 'variant:b@west=1']);
});

it('subtracts earlier needs inside the candidate location', function (): void {
    $needs = [alloc_need('x', 3, [alloc_component('a')]), alloc_need('y', 3, [alloc_component('a')])];

    expect(single($needs, [alloc_source('main', 1, ['a' => 5]), alloc_source('east', 2, ['a' => 6])]))
        ->toBe(['variant:x@east=3', 'variant:y@east=3']);
});

it('prefers real stock to backorder and falls back to the first location whose tracked stock suffices', function (): void {
    $needs = [alloc_need('x', 4, [alloc_component('b', 1, backorder: true)])];

    expect(single($needs, [alloc_source('main', 1, ['b' => 0]), alloc_source('east', 2, ['b' => 4])]))->toBe(['variant:x@east=4'])
        ->and(single($needs, [alloc_source('main', 1, ['b' => 0]), alloc_source('east', 2, ['b' => 1])]))->toBe(['variant:x@main=4']);
});

it('leaves the plan empty when no location holds everything', function (): void {
    $needs = [alloc_need('a', 2, [alloc_component('a')]), alloc_need('b', 1, [alloc_component('b')])];

    expect(single($needs, [alloc_source('main', 1, ['a' => 5]), alloc_source('east', 2, ['b' => 5])]))->toBe([])
        ->and(single($needs, []))->toBe([]);
});

it('reports the best single location as capacity, or null when stock does not limit', function (): void {
    $strategy = new SingleLocationAllocation;
    $sources = [alloc_source('main', 1, ['wheel' => 5, 'frame' => 5]), alloc_source('east', 2, ['wheel' => 10, 'frame' => 1])];

    expect($strategy->capacity(alloc_need('bike', 1, [alloc_component('wheel', 2), alloc_component('frame')]), $sources))->toBe(2)
        ->and($strategy->capacity(alloc_need('bike', 1, [alloc_component('wheel', 2)]), []))->toBe(0)
        ->and($strategy->capacity(alloc_need('x', 1, [alloc_component('b', 1, backorder: true)]), $sources))->toBeNull();
});
