<?php

declare(strict_types=1);

namespace Commerz\Ports\Delivery\Contracts;

use Commerz\Ports\Delivery\Data\OrderRoutingContext;
use Commerz\Ports\Delivery\Data\RoutingCandidate;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * Pluggable rule in an order-routing pipeline, contributed under kind `order_routing_rule`.
 * Pure: filters and/or re-orders candidate locations without side effects or I/O.
 */
#[Spi(kind: SpiKind::Implement, since: '0.1')]
interface OrderRoutingRule
{
    public const string KIND = 'order_routing_rule';

    public function key(): string;

    /**
     * Filters and/or re-orders candidate locations according to this rule.
     *
     * @param  list<RoutingCandidate>  $candidates
     * @return list<RoutingCandidate>
     */
    public function apply(array $candidates, OrderRoutingContext $context): array;
}
