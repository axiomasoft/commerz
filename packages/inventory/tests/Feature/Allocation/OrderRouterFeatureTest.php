<?php

declare(strict_types=1);

use Commerz\Identity\SellableRef;
use Commerz\Inventory\Allocation\Contracts\OrderRouter;
use Commerz\Inventory\Allocation\Data\AllocationNeed;
use Commerz\Inventory\Allocation\Data\AllocationRequest;
use Commerz\Inventory\Allocation\Data\NeedComponent;
use Commerz\Inventory\Allocation\Data\OrderRoutingRequest;
use Commerz\Inventory\Allocation\Data\SourceStock;
use Commerz\Inventory\Allocation\OrderRouterPipeline;
use Commerz\Inventory\Facades\Inventory as InventoryFacade;
use Commerz\Inventory\Items\Data\StockItemId;
use Commerz\Inventory\Locations\Data\LocationId;
use Commerz\Ports\Delivery\Contracts\OrderRoutingRule;
use Commerz\Ports\Delivery\Data\FulfillmentConstraints;
use Commerz\Ports\Delivery\Data\OrderRoutingContext;
use Commerz\Ports\Delivery\Data\RoutingLocationProfile;
use Commerz\Stores\Data\ChannelId;
use Packman\Plugins\Contracts\Contributions\Contributions;

it('binds OrderRouter in the container, exposes it on Inventory facade, and contributes built-in routing rules', function (): void {
    $router = app(OrderRouter::class);
    $contributions = app(Contributions::class);

    $ruleKeys = [
        'fulfillment_constraints',
        'minimize_split_shipments',
        'geo_proximity',
        'market_affinity',
        'daily_location_quota',
    ];

    expect($router)->toBeInstanceOf(OrderRouterPipeline::class)
        ->and(InventoryFacade::orderRouter())->toBe($router)
        ->and(array_map(
            static fn (string $key): string => $contributions->get(OrderRoutingRule::KIND, $key, OrderRoutingRule::class)->key(),
            $ruleKeys,
        ))->toBe($ruleKeys);

    $itemA = StockItemId::generate();
    $itemB = StockItemId::generate();

    $result = $router->route(new OrderRoutingRequest(
        allocation: new AllocationRequest(
            ChannelId::generate(),
            [
                new AllocationNeed(SellableRef::of('variant', 'milk'), 2, [new NeedComponent($itemA, 1)]),
                new AllocationNeed(SellableRef::of('variant', 'cheese'), 1, [new NeedComponent($itemB, 1)]),
            ],
            [
                new SourceStock(LocationId::generate(), 'wh-warm', 1, [(string) $itemA => 10, (string) $itemB => 10]),
                new SourceStock(LocationId::generate(), 'wh-cold-partial', 2, [(string) $itemA => 10]),
                new SourceStock(LocationId::generate(), 'wh-cold-full', 3, [(string) $itemA => 5, (string) $itemB => 5]),
            ],
        ),
        context: new OrderRoutingContext(
            constraints: new FulfillmentConstraints(coldChain: true),
            locationProfiles: [
                new RoutingLocationProfile('wh-warm', coldChainCapable: false),
                new RoutingLocationProfile('wh-cold-partial', coldChainCapable: true),
                new RoutingLocationProfile('wh-cold-full', coldChainCapable: true),
            ],
        ),
    ));

    expect($result->isComplete())->toBeTrue()
        ->and($result->isSplit())->toBeFalse()
        ->and($result->assignments)->toHaveCount(1)
        ->and($result->assignments[0]->locationCode)->toBe('wh-cold-full')
        ->and($result->assignments[0]->constraints->coldChain)->toBeTrue();
});
