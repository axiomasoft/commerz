<?php

declare(strict_types=1);

namespace Commerz\Inventory\Allocation\Rules;

use Commerz\Ports\Delivery\Contracts\OrderRoutingRule;
use Commerz\Ports\Delivery\Data\OrderRoutingContext;
use Commerz\Ports\Delivery\Data\RoutingCandidate;
use Override;
use Packman\Attributes\Api;

/**
 * Minimizes split shipments: if any single location can fulfill the entire cart, drops partial candidates so
 * the order is never unnecessarily split; otherwise prefers locations covering the most lines and units.
 */
#[Api(since: '0.1')]
final readonly class MinimizeSplitShipmentsRule implements OrderRoutingRule
{
    public const string KEY = 'minimize_split_shipments';

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
        $singleLocation = array_values(array_filter(
            $candidates,
            static fn (RoutingCandidate $candidate): bool => $candidate->canFulfillAll,
        ));

        if ($singleLocation !== []) {
            return $singleLocation;
        }

        if ($context->constraints->mustShipTogether) {
            return [];
        }

        usort(
            $candidates,
            static function (RoutingCandidate $a, RoutingCandidate $b): int {
                $byLines = $b->fulfillableLineCount <=> $a->fulfillableLineCount;

                if ($byLines !== 0) {
                    return $byLines;
                }

                return $b->fulfillableUnits <=> $a->fulfillableUnits;
            },
        );

        return $candidates;
    }
}
