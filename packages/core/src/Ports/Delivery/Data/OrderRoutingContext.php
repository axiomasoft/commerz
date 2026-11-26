<?php

declare(strict_types=1);

namespace Commerz\Ports\Delivery\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Ports\Delivery\Contracts\OrderRoutingRule;
use Packman\Attributes\Api;

/**
 * Shared context supplied to every {@see OrderRoutingRule} during routing.
 */
#[Api(since: '0.1')]
final readonly class OrderRoutingContext
{
    /** @var array<string, RoutingLocationProfile> */
    public array $locationProfiles;

    /**
     * @param  list<RoutingLocationProfile>|array<string, RoutingLocationProfile>  $locationProfiles
     */
    public function __construct(
        public FulfillmentConstraints $constraints = new FulfillmentConstraints,
        public ?Address $destination = null,
        public ?GeoPoint $destinationCoordinates = null,
        public ?string $marketCode = null,
        array $locationProfiles = [],
    ) {
        if ($marketCode !== null && preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,63}\z/', $marketCode) !== 1) {
            throw new InvalidCommand('marketCode', "market '{$marketCode}' must match [A-Za-z0-9][A-Za-z0-9_.-]{0,63}", 'delivery.invalid_routing_context');
        }

        $indexed = [];

        foreach ($locationProfiles as $profile) {
            if (isset($indexed[$profile->locationCode])) {
                throw new InvalidCommand('locationProfiles', "location '{$profile->locationCode}' appears more than once", 'delivery.invalid_routing_context');
            }

            $indexed[$profile->locationCode] = $profile;
        }

        $this->locationProfiles = $indexed;
    }

    public function profileFor(string $locationCode): RoutingLocationProfile
    {
        return $this->locationProfiles[$locationCode] ?? new RoutingLocationProfile($locationCode);
    }
}
