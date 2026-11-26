<?php

declare(strict_types=1);

namespace Commerz\Ports\Delivery\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Ports\Delivery\Contracts\OrderRoutingRule;
use Commerz\Ports\Stock\Data\StockLines;
use Packman\Attributes\Api;

/**
 * A candidate fulfillment location evaluated by an {@see OrderRoutingRule}.
 */
#[Api(since: '0.1')]
final readonly class RoutingCandidate
{
    public function __construct(
        public string $locationCode,
        public int $priority,
        public RoutingLocationProfile $profile,
        public bool $canFulfillAll,
        public int $fulfillableUnits,
        public int $fulfillableLineCount,
    ) {
        if ($locationCode === '') {
            throw new InvalidCommand('locationCode', 'must not be empty', 'delivery.invalid_routing_candidate');
        }

        StockLines::assertLocation($locationCode);

        if ($fulfillableUnits < 0) {
            throw new InvalidCommand('fulfillableUnits', 'must not be negative', 'delivery.invalid_routing_candidate');
        }

        if ($fulfillableLineCount < 0) {
            throw new InvalidCommand('fulfillableLineCount', 'must not be negative', 'delivery.invalid_routing_candidate');
        }
    }
}
