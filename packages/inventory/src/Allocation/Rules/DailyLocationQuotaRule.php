<?php

declare(strict_types=1);

namespace Commerz\Inventory\Allocation\Rules;

use Commerz\Ports\Delivery\Contracts\OrderRoutingRule;
use Commerz\Ports\Delivery\Data\OrderRoutingContext;
use Commerz\Ports\Delivery\Data\RoutingCandidate;
use Override;
use Packman\Attributes\Api;

/**
 * Excludes candidate locations that have exhausted their daily fulfillment assignment quota.
 */
#[Api(since: '0.1')]
final readonly class DailyLocationQuotaRule implements OrderRoutingRule
{
    public const string KEY = 'daily_location_quota';

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
        return array_values(array_filter(
            $candidates,
            static fn (RoutingCandidate $candidate): bool => $candidate->profile->hasDailyQuotaRemaining(),
        ));
    }
}
