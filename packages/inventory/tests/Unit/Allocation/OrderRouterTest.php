<?php

declare(strict_types=1);

use Commerz\Inventory\Allocation\Data\OrderRoutingRequest;
use Commerz\Inventory\Allocation\OrderRouterPipeline;
use Commerz\Inventory\Allocation\Rules\DailyLocationQuotaRule;
use Commerz\Inventory\Allocation\Rules\FulfillmentConstraintsRule;
use Commerz\Inventory\Allocation\Rules\GeoProximityRule;
use Commerz\Inventory\Allocation\Rules\MarketAffinityRule;
use Commerz\Inventory\Allocation\Rules\MinimizeSplitShipmentsRule;
use Commerz\Ports\Delivery\Contracts\OrderRoutingRule;
use Commerz\Ports\Delivery\Data\Address;
use Commerz\Ports\Delivery\Data\FulfillmentAssignment;
use Commerz\Ports\Delivery\Data\FulfillmentConstraints;
use Commerz\Ports\Delivery\Data\GeoPoint;
use Commerz\Ports\Delivery\Data\OrderRoutingContext;
use Commerz\Ports\Delivery\Data\RoutingCandidate;
use Commerz\Ports\Delivery\Data\RoutingLocationProfile;

require_once __DIR__.'/Fixtures.php';

it('prefers a single warehouse that can fulfill the whole cart and does not unnecessarily split', function (): void {
    $router = OrderRouterPipeline::default();

    // `main` has higher priority (1) and is closer, but only has item `a`.
    // `east` has lower priority (2) and is farther, but can fulfill BOTH `a` and `b`.
    // Single-warehouse preference must route the entire cart to `east` with zero split.
    $berlin = new GeoPoint(52_520_000, 13_405_000);
    $potsdam = new GeoPoint(52_390_569, 13_064_473);
    $munich = new GeoPoint(48_135_125, 11_581_981);

    $request = new OrderRoutingRequest(
        allocation: alloc_request(
            [
                alloc_need('a', 2, [alloc_component('a')]),
                alloc_need('b', 3, [alloc_component('b')]),
            ],
            [
                alloc_source('main', 1, ['a' => 10, 'b' => 0]),
                alloc_source('east', 2, ['a' => 2, 'b' => 3]),
            ],
        ),
        context: new OrderRoutingContext(
            destinationCoordinates: $berlin,
            locationProfiles: [
                new RoutingLocationProfile('main', coordinates: $potsdam),
                new RoutingLocationProfile('east', coordinates: $munich),
            ],
        ),
    );

    $result = $router->route($request);

    expect($result->isComplete())->toBeTrue()
        ->and($result->isSplit())->toBeFalse()
        ->and($result->locationCount())->toBe(1)
        ->and($result->assignments)->toHaveCount(1)
        ->and($result->assignments[0]->locationCode)->toBe('east')
        ->and(alloc_describe($result->plan))->toBe(['variant:a@east=2', 'variant:b@east=3'])
        ->and($result->appliedRules)->toBe([
            FulfillmentConstraintsRule::KEY,
            DailyLocationQuotaRule::KEY,
            MinimizeSplitShipmentsRule::KEY,
            GeoProximityRule::KEY,
            MarketAffinityRule::KEY,
        ]);
});

it('splits cleanly across minimal locations when no single warehouse can fulfill the whole cart', function (): void {
    $router = OrderRouterPipeline::default();

    // Cart needs a=1, b=1, c=1.
    // `main` covers only a; `east` covers only b; `west` covers a+b; `north` covers c.
    // MinimizeSplitShipmentsRule must pick `west` (2 lines) + `north` (1 line) = 2 shipments instead of 3.
    $request = new OrderRoutingRequest(
        allocation: alloc_request(
            [
                alloc_need('a', 1, [alloc_component('a')]),
                alloc_need('b', 1, [alloc_component('b')]),
                alloc_need('c', 1, [alloc_component('c')]),
            ],
            [
                alloc_source('main', 1, ['a' => 1]),
                alloc_source('east', 2, ['b' => 1]),
                alloc_source('west', 3, ['a' => 1, 'b' => 1]),
                alloc_source('north', 4, ['c' => 1]),
            ],
        ),
    );

    $result = $router->route($request);

    expect($result->isComplete())->toBeTrue()
        ->and($result->isSplit())->toBeTrue()
        ->and($result->locationCount())->toBe(2)
        ->and(array_map(static fn (FulfillmentAssignment $a): string => $a->locationCode, $result->assignments))->toBe(['west', 'north'])
        ->and(alloc_describe($result->plan))->toBe(['variant:a@west=1', 'variant:b@west=1', 'variant:c@north=1']);
});

it('refuses to split when mustShipTogether constraint is active', function (): void {
    $router = OrderRouterPipeline::default();

    $request = new OrderRoutingRequest(
        allocation: alloc_request(
            [
                alloc_need('a', 1, [alloc_component('a')]),
                alloc_need('b', 1, [alloc_component('b')]),
            ],
            [
                alloc_source('main', 1, ['a' => 5]),
                alloc_source('east', 2, ['b' => 5]),
            ],
        ),
        context: new OrderRoutingContext(
            constraints: new FulfillmentConstraints(mustShipTogether: true),
        ),
    );

    $result = $router->route($request);

    expect($result->isComplete())->toBeFalse()
        ->and($result->assignments)->toBe([])
        ->and($result->plan->allocations)->toBe([])
        ->and(array_map(strval(...), $result->unplaced))->toBe(['variant:a', 'variant:b']);
});

it('routes cold-chain orders exclusively to cold-chain-capable locations', function (): void {
    $router = OrderRouterPipeline::default();
    $berlin = new GeoPoint(52_520_000, 13_405_000);

    $request = new OrderRoutingRequest(
        allocation: alloc_request(
            [alloc_need('vaccine', 4, [alloc_component('vaccine')])],
            [
                alloc_source('main', 1, ['vaccine' => 100]),
                alloc_source('east', 2, ['vaccine' => 10]),
            ],
        ),
        context: new OrderRoutingContext(
            constraints: new FulfillmentConstraints(coldChain: true),
            destinationCoordinates: $berlin,
            locationProfiles: [
                new RoutingLocationProfile('main', coldChainCapable: false, coordinates: $berlin),
                new RoutingLocationProfile('east', coldChainCapable: true, coordinates: new GeoPoint(53_551_086, 9_993_682)),
            ],
        ),
    );

    $result = $router->route($request);

    expect($result->isComplete())->toBeTrue()
        ->and($result->locationCount())->toBe(1)
        ->and($result->assignments[0]->locationCode)->toBe('east')
        ->and($result->assignments[0]->constraints->coldChain)->toBeTrue()
        ->and(alloc_describe($result->plan))->toBe(['variant:vaccine@east=4']);
});

it('never falls back to non-cold-chain warehouses even when custom pipelines omit FulfillmentConstraintsRule', function (): void {
    $customRouter = new OrderRouterPipeline([new GeoProximityRule]);

    $request = new OrderRoutingRequest(
        allocation: alloc_request(
            [alloc_need('gelato', 2, [alloc_component('gelato')])],
            [alloc_source('main', 1, ['gelato' => 50])],
        ),
        context: new OrderRoutingContext(
            constraints: new FulfillmentConstraints(coldChain: true),
            locationProfiles: [
                new RoutingLocationProfile('main', coldChainCapable: false),
            ],
        ),
    );

    $result = $customRouter->route($request);

    expect($result->isComplete())->toBeFalse()
        ->and($result->assignments)->toBe([])
        ->and(array_map(strval(...), $result->unplaced))->toBe(['variant:gelato']);
});

it('enforces oversized and requiredLocationTags constraints', function (): void {
    $router = OrderRouterPipeline::default();

    $request = new OrderRoutingRequest(
        allocation: alloc_request(
            [alloc_need('battery-rack', 1, [alloc_component('rack')])],
            [
                alloc_source('main', 1, ['rack' => 5]),
                alloc_source('east', 2, ['rack' => 5]),
                alloc_source('west', 3, ['rack' => 5]),
            ],
        ),
        context: new OrderRoutingContext(
            constraints: new FulfillmentConstraints(oversized: true, requiredLocationTags: ['hazmat', 'bonded']),
            locationProfiles: [
                new RoutingLocationProfile('main', oversizedCapable: true, tags: ['hazmat']),
                new RoutingLocationProfile('east', oversizedCapable: false, tags: ['hazmat', 'bonded']),
                new RoutingLocationProfile('west', oversizedCapable: true, tags: ['hazmat', 'bonded', 'dock']),
            ],
        ),
    );

    $result = $router->route($request);

    expect($result->isComplete())->toBeTrue()
        ->and($result->assignments[0]->locationCode)->toBe('west');
});

it('combines daily quota, market affinity, and geo-proximity to choose the best single warehouse', function (): void {
    $router = OrderRouterPipeline::default();

    // Destination: Dresden (DE).
    // - `main`: DE, 10km away, but daily quota exhausted (50/50) -> excluded
    // - `east`: CZ (Prague), 120km away (closer, out-of-market)
    // - `west`: DE (Munich), 360km away (in-market, farther)
    // - `north`: DE (Berlin), 165km away (in-market, closer than Munich!)
    $dresden = new GeoPoint(51_050_409, 13_737_262);

    $request = new OrderRoutingRequest(
        allocation: alloc_request(
            [alloc_need('shirt', 2, [alloc_component('shirt')])],
            [
                alloc_source('main', 1, ['shirt' => 10]),
                alloc_source('east', 2, ['shirt' => 10]),
                alloc_source('west', 3, ['shirt' => 10]),
                alloc_source('north', 4, ['shirt' => 10]),
            ],
        ),
        context: new OrderRoutingContext(
            destination: new Address('DE', city: 'Dresden'),
            destinationCoordinates: $dresden,
            marketCode: 'DE',
            locationProfiles: [
                new RoutingLocationProfile('main', coordinates: $dresden, marketCodes: ['DE'], dailyQuota: 50, dailyAssignedCount: 50),
                new RoutingLocationProfile('east', coordinates: new GeoPoint(50_075_538, 14_437_800), marketCodes: ['CZ'], dailyQuota: 100, dailyAssignedCount: 10),
                new RoutingLocationProfile('west', coordinates: new GeoPoint(48_135_125, 11_581_981), marketCodes: ['DE'], dailyQuota: 100, dailyAssignedCount: 10),
                new RoutingLocationProfile('north', coordinates: new GeoPoint(52_520_000, 13_405_000), marketCodes: ['DE'], dailyQuota: 100, dailyAssignedCount: 10),
            ],
        ),
    );

    $result = $router->route($request);

    expect($result->isComplete())->toBeTrue()
        ->and($result->locationCount())->toBe(1)
        ->and($result->assignments[0]->locationCode)->toBe('north');
});

it('supports custom pluggable OrderRoutingRule extensions and sanitizes unknown candidates', function (): void {
    $preferEast = new readonly class implements OrderRoutingRule
    {
        public function key(): string
        {
            return 'acme.prefer_east';
        }

        public function apply(array $candidates, OrderRoutingContext $context): array
        {
            $rogue = new RoutingCandidate('rogue-wh', 0, new RoutingLocationProfile('rogue-wh'), true, 999, 99);
            usort($candidates, static fn (RoutingCandidate $a, RoutingCandidate $b): int => ($a->locationCode === 'east' ? -1 : 0) <=> ($b->locationCode === 'east' ? -1 : 0));

            return [$rogue, ...$candidates];
        }
    };

    $router = new OrderRouterPipeline([new MinimizeSplitShipmentsRule])->withRule($preferEast);

    $result = $router->route(new OrderRoutingRequest(
        allocation: alloc_request(
            [alloc_need('a', 1, [alloc_component('a')])],
            [
                alloc_source('main', 1, ['a' => 5]),
                alloc_source('east', 2, ['a' => 5]),
            ],
        ),
    ));

    expect($router->rules())->toHaveCount(2)
        ->and($result->isComplete())->toBeTrue()
        ->and($result->assignments[0]->locationCode)->toBe('east')
        ->and($result->appliedRules)->toBe([MinimizeSplitShipmentsRule::KEY, 'acme.prefer_east']);
});
