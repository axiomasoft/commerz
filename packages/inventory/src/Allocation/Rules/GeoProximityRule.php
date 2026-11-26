<?php

declare(strict_types=1);

namespace Commerz\Inventory\Allocation\Rules;

use Commerz\Ports\Delivery\Contracts\OrderRoutingRule;
use Commerz\Ports\Delivery\Data\OrderRoutingContext;
use Commerz\Ports\Delivery\Data\RoutingCandidate;
use Override;
use Packman\Attributes\Api;

/**
 * Orders candidate locations closest-first to {@see OrderRoutingContext::$destinationCoordinates}.
 * Locations without coordinates follow those with coordinates, preserving relative order on ties.
 */
#[Api(since: '0.1')]
final readonly class GeoProximityRule implements OrderRoutingRule
{
    public const string KEY = 'geo_proximity';

    #[Override]
    public function key(): string
    {
        return self::KEY;
    }

    /**
     * @param  list<RoutingCandidate>  $candidates
     * @return list<RoutingCandidate>
     */
    #[Override]
    public function apply(array $candidates, OrderRoutingContext $context): array
    {
        $destination = $context->destinationCoordinates;

        if ($destination === null) {
            return $candidates;
        }

        usort(
            $candidates,
            static function (RoutingCandidate $a, RoutingCandidate $b) use ($destination): int {
                $coordsA = $a->profile->coordinates;
                $coordsB = $b->profile->coordinates;

                if ($coordsA === null && $coordsB === null) {
                    return 0;
                }

                if ($coordsA === null) {
                    return 1;
                }

                if ($coordsB === null) {
                    return -1;
                }

                return $coordsA->distanceMetersTo($destination) <=> $coordsB->distanceMetersTo($destination);
            },
        );

        return $candidates;
    }
}
