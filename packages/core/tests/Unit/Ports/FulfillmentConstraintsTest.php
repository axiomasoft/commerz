<?php

declare(strict_types=1);

use Commerz\Errors\InvalidCommand;
use Commerz\Identity\SellableRef;
use Commerz\Ports\Delivery\Data\FulfillmentAssignment;
use Commerz\Ports\Delivery\Data\FulfillmentAssignmentLine;
use Commerz\Ports\Delivery\Data\FulfillmentConstraints;
use Commerz\Ports\Delivery\Data\GeoPoint;
use Commerz\Ports\Delivery\Data\OrderRoutingContext;
use Commerz\Ports\Delivery\Data\RoutingCandidate;
use Commerz\Ports\Delivery\Data\RoutingLocationProfile;

it('constructs, merges, serializes, and validates FulfillmentConstraints', function (): void {
    $none = FulfillmentConstraints::none();

    expect($none->isEmpty())->toBeTrue()
        ->and($none->coldChain)->toBeFalse()
        ->and($none->oversized)->toBeFalse()
        ->and($none->mustShipTogether)->toBeFalse()
        ->and($none->requiredLocationTags)->toBe([]);

    $cold = new FulfillmentConstraints(coldChain: true, requiredLocationTags: ['pharma', 'pharma', 'bonded']);
    $heavy = new FulfillmentConstraints(oversized: true, mustShipTogether: true, requiredLocationTags: ['bonded', 'dock']);
    $merged = $cold->merge($heavy);

    expect($cold->isEmpty())->toBeFalse()
        ->and($cold->requiredLocationTags)->toBe(['pharma', 'bonded'])
        ->and($merged->coldChain)->toBeTrue()
        ->and($merged->oversized)->toBeTrue()
        ->and($merged->mustShipTogether)->toBeTrue()
        ->and($merged->requiredLocationTags)->toBe(['pharma', 'bonded', 'dock'])
        ->and(FulfillmentConstraints::fromArray($merged->toArray()))->toEqual($merged);

    expect(fn () => new FulfillmentConstraints(requiredLocationTags: ['Bad Tag']))->toThrow(InvalidCommand::class)
        ->and(fn () => FulfillmentConstraints::fromArray(['required_location_tags' => [123]]))->toThrow(InvalidCommand::class);
});

it('validates GeoPoint micro-degree coordinates and computes exact meter distances', function (): void {
    $berlin = new GeoPoint(52_520_000, 13_405_000);
    $hamburg = new GeoPoint(53_551_086, 9_993_682);
    $munich = new GeoPoint(48_135_125, 11_581_981);

    expect($berlin->distanceMetersTo($berlin))->toBe(0)
        ->and($berlin->distanceMetersTo($hamburg))->toBeGreaterThan(200_000)
        ->and($berlin->distanceMetersTo($hamburg))->toBeLessThan(300_000)
        ->and($berlin->distanceMetersTo($hamburg))->toBeLessThan($berlin->distanceMetersTo($munich));

    expect(fn () => new GeoPoint(90_000_001, 0))->toThrow(InvalidCommand::class)
        ->and(fn () => new GeoPoint(0, -180_000_001))->toThrow(InvalidCommand::class);
});

it('evaluates RoutingLocationProfile capabilities, quotas, markets, and routing DTOs', function (): void {
    $profile = new RoutingLocationProfile(
        locationCode: 'wh-cold-de',
        coldChainCapable: true,
        oversizedCapable: false,
        tags: ['pharma', 'bonded', 'pharma'],
        coordinates: new GeoPoint(52_520_000, 13_405_000),
        marketCodes: ['de', 'eu', 'DE'],
        dailyQuota: 10,
        dailyAssignedCount: 7,
    );

    expect($profile->tags)->toBe(['pharma', 'bonded'])
        ->and($profile->marketCodes)->toBe(['DE', 'EU'])
        ->and($profile->hasDailyQuotaRemaining())->toBeTrue()
        ->and($profile->remainingDailyQuota())->toBe(3)
        ->and($profile->servesMarket('de'))->toBeTrue()
        ->and($profile->servesMarket('US'))->toBeFalse()
        ->and($profile->satisfies(new FulfillmentConstraints(coldChain: true, requiredLocationTags: ['pharma'])))->toBeTrue()
        ->and($profile->satisfies(new FulfillmentConstraints(oversized: true)))->toBeFalse()
        ->and($profile->satisfies(new FulfillmentConstraints(requiredLocationTags: ['hazmat'])))->toBeFalse();

    $context = new OrderRoutingContext(
        constraints: new FulfillmentConstraints(coldChain: true),
        marketCode: 'DE',
        locationProfiles: [$profile],
    );

    expect($context->profileFor('wh-cold-de'))->toEqual($profile)
        ->and($context->profileFor('wh-unknown')->coldChainCapable)->toBeFalse();

    $assignment = new FulfillmentAssignment(
        locationCode: 'wh-cold-de',
        lines: [new FulfillmentAssignmentLine(SellableRef::of('variant', 'v1'), 2)],
        constraints: new FulfillmentConstraints(coldChain: true),
    );

    expect($assignment->locationCode)->toBe('wh-cold-de')
        ->and($assignment->lines)->toHaveCount(1);

    expect(fn () => new RoutingLocationProfile(''))->toThrow(InvalidCommand::class)
        ->and(fn () => new RoutingLocationProfile('wh-1', dailyQuota: -1))->toThrow(InvalidCommand::class)
        ->and(fn () => new RoutingLocationProfile('wh-1', dailyAssignedCount: -1))->toThrow(InvalidCommand::class)
        ->and(fn () => new OrderRoutingContext(locationProfiles: [$profile, $profile]))->toThrow(InvalidCommand::class)
        ->and(fn () => new RoutingCandidate('wh-1', 1, $profile, true, -1, 0))->toThrow(InvalidCommand::class)
        ->and(fn () => new FulfillmentAssignmentLine(SellableRef::of('variant', 'v1'), 0))->toThrow(InvalidCommand::class)
        ->and(fn () => new FulfillmentAssignment('wh-1', [
            new FulfillmentAssignmentLine(SellableRef::of('variant', 'v1'), 1),
            new FulfillmentAssignmentLine(SellableRef::of('variant', 'v1'), 2),
        ]))->toThrow(InvalidCommand::class);
});
