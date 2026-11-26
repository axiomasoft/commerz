<?php

declare(strict_types=1);

namespace Commerz\Inventory\Allocation\Rules;

use Commerz\Ports\Delivery\Contracts\OrderRoutingRule;
use Commerz\Ports\Delivery\Data\OrderRoutingContext;
use Commerz\Ports\Delivery\Data\RoutingCandidate;
use Override;
use Packman\Attributes\Api;

/**
 * Prefers locations that explicitly serve the requested market (or destination country),
 * preserving relative order within in-market and out-of-market groups.
 */
#[Api(since: '0.1')]
final readonly class MarketAffinityRule implements OrderRoutingRule
{
    public const string KEY = 'market_affinity';

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
        $market = $context->marketCode ?? $context->destination?->country;

        if ($market === null) {
            return $candidates;
        }

        $matched = [];
        $others = [];

        foreach ($candidates as $candidate) {
            if ($candidate->profile->servesMarket($market)) {
                $matched[] = $candidate;
            } else {
                $others[] = $candidate;
            }
        }

        return [...$matched, ...$others];
    }
}
