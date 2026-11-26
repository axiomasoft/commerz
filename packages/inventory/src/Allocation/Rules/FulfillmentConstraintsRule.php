<?php

declare(strict_types=1);

namespace Commerz\Inventory\Allocation\Rules;

use Commerz\Ports\Delivery\Contracts\OrderRoutingRule;
use Commerz\Ports\Delivery\Data\FulfillmentConstraints;
use Commerz\Ports\Delivery\Data\OrderRoutingContext;
use Commerz\Ports\Delivery\Data\RoutingCandidate;
use Override;
use Packman\Attributes\Api;

/**
 * Eliminates candidate locations that do not satisfy {@see FulfillmentConstraints}
 * (cold chain, oversized freight, and required location tags).
 */
#[Api(since: '0.1')]
final readonly class FulfillmentConstraintsRule implements OrderRoutingRule
{
    public const string KEY = 'fulfillment_constraints';

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
            static fn (RoutingCandidate $candidate): bool => $candidate->profile->satisfies($context->constraints),
        ));
    }
}
