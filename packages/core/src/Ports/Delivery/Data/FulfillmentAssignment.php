<?php

declare(strict_types=1);

namespace Commerz\Ports\Delivery\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Ports\Stock\Data\StockLines;
use Packman\Attributes\Api;

/**
 * Core-boundary warehouse assignment produced by order routing and consumed when creating a fulfillment order.
 */
#[Api(since: '0.1')]
final readonly class FulfillmentAssignment
{
    /**
     * @param  non-empty-list<FulfillmentAssignmentLine>  $lines
     */
    public function __construct(
        public string $locationCode,
        public array $lines,
        public FulfillmentConstraints $constraints = new FulfillmentConstraints,
    ) {
        if ($locationCode === '') {
            throw new InvalidCommand('locationCode', 'must not be empty', 'delivery.invalid_assignment');
        }

        StockLines::assertLocation($locationCode);

        $seen = [];

        foreach ($lines as $line) {
            $key = (string) $line->sellable;

            if (isset($seen[$key])) {
                throw new InvalidCommand('lines', "'{$key}' appears more than once", 'delivery.invalid_assignment');
            }

            $seen[$key] = true;
        }
    }
}
