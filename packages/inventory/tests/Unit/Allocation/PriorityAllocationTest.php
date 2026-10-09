<?php

declare(strict_types=1);

use Commerz\Inventory\Allocation\Internal\PriorityAllocation;

require_once __DIR__.'/Fixtures.php';

function priority(array $needs, array $sources): array
{
    return alloc_describe(new PriorityAllocation()->allocate(alloc_request($needs, $sources)));
}

it('has the key priority', function (): void {
    expect(new PriorityAllocation()->key())->toBe('priority');
});

it('fills sources in order and splits a need across locations', function (): void {
    $plan = priority(
        [alloc_need('a', 10, [alloc_component('a')])],
        [alloc_source('main', 1, ['a' => 6]), alloc_source('east', 2, ['a' => 7]), alloc_source('west', 3, ['a' => 9])],
    );

    expect($plan)->toBe(['variant:a@main=6', 'variant:a@east=4']);
});

it('takes a whole need from the first location that has it', function (): void {
    $plan = priority([alloc_need('a', 3, [alloc_component('a')])], [alloc_source('main', 1, ['a' => 3]), alloc_source('east', 2, ['a' => 30])]);

    expect($plan)->toBe(['variant:a@main=3']);
});

it('places a kit by whole units: 2 wheels and 1 frame per bike', function (): void {
    $bike = alloc_need('bike', 3, [alloc_component('wheel', 2), alloc_component('frame')]);

    $plan = priority([$bike], [
        alloc_source('main', 1, ['wheel' => 5, 'frame' => 5]),
        alloc_source('east', 2, ['wheel' => 10, 'frame' => 1]),
    ]);

    // main: min(5/2, 5) = 2 bikes (one wheel stays); east: min(10/2, 1) = 1 bike
    expect($plan)->toBe(['variant:bike@main=2', 'variant:bike@east=1']);
});

it('never splits the components of one unit across locations', function (): void {
    $bike = alloc_need('bike', 1, [alloc_component('wheel', 2), alloc_component('frame')]);

    $plan = priority([$bike], [alloc_source('main', 1, ['wheel' => 2]), alloc_source('east', 2, ['frame' => 1])]);

    expect($plan)->toBe([]);
});

it('leaves a need that does not fit completely out of the plan and keeps its stock for the next', function (): void {
    $plan = priority(
        [alloc_need('big', 100, [alloc_component('a')]), alloc_need('small', 2, [alloc_component('a')])],
        [alloc_source('main', 1, ['a' => 5])],
    );

    expect($plan)->toBe(['variant:small@main=2']);
});

it('subtracts what earlier needs took from items they share', function (): void {
    $plan = priority(
        [alloc_need('x', 3, [alloc_component('a')]), alloc_need('y', 3, [alloc_component('a')])],
        [alloc_source('main', 1, ['a' => 4]), alloc_source('east', 2, ['a' => 2])],
    );

    expect($plan)->toBe(['variant:x@main=3', 'variant:y@main=1', 'variant:y@east=2']);
});

it('puts units beyond free stock of a backorder item into the first source after real stock is used', function (): void {
    $plan = priority([alloc_need('x', 5, [alloc_component('b', 1, backorder: true)])], [alloc_source('main', 1, ['b' => 0]), alloc_source('east', 2, ['b' => 2])]);

    expect($plan)->toBe(['variant:x@main=3', 'variant:x@east=2']);
});

it('backorders a kit only as far as its tracked components reach', function (): void {
    $kit = fn (int $units) => alloc_need('kit', $units, [alloc_component('a'), alloc_component('b', 1, backorder: true)]);
    $sources = [alloc_source('main', 1, ['a' => 3, 'b' => 1])];

    expect(priority([$kit(3)], $sources))->toBe(['variant:kit@main=3'])
        ->and(priority([$kit(4)], $sources))->toBe([]);
});

it('places nothing without sources', function (): void {
    expect(priority([alloc_need('a', 1, [alloc_component('a')])], []))->toBe([]);
});

it('reports the units stock allows as capacity, or null when stock does not limit', function (): void {
    $strategy = new PriorityAllocation;
    $sources = [alloc_source('main', 1, ['wheel' => 5, 'frame' => 5]), alloc_source('east', 2, ['wheel' => 10, 'frame' => 1])];
    $bike = alloc_need('bike', 1, [alloc_component('wheel', 2), alloc_component('frame')]);
    $backordered = alloc_need('x', 1, [alloc_component('b', 1, backorder: true)]);
    $kit = alloc_need('kit', 1, [alloc_component('wheel'), alloc_component('b', 1, backorder: true)]);

    expect($strategy->capacity($bike, $sources))->toBe(3)
        ->and($strategy->capacity($bike, []))->toBe(0)
        ->and($strategy->capacity($backordered, $sources))->toBeNull()
        ->and($strategy->capacity($kit, $sources))->toBe(15)
        ->and($strategy->capacity(alloc_need('z', 1, [alloc_component('missing')]), $sources))->toBe(0);
});

it('saturates capacity at the int64 limit', function (): void {
    $need = alloc_need('a', 1, [alloc_component('a')]);

    expect(new PriorityAllocation()->capacity($need, [alloc_source('main', 1, ['a' => PHP_INT_MAX]), alloc_source('east', 2, ['a' => 5])]))->toBe(PHP_INT_MAX);
});
