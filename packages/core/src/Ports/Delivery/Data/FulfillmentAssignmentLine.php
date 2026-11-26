<?php

declare(strict_types=1);

namespace Commerz\Ports\Delivery\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Identity\SellableRef;
use Packman\Attributes\Api;

/** Units of one sellable assigned to a fulfillment location by order routing. */
#[Api(since: '0.1')]
final readonly class FulfillmentAssignmentLine
{
    public function __construct(
        public SellableRef $sellable,
        public int $quantity,
    ) {
        if ($quantity < 1) {
            throw new InvalidCommand('quantity', 'must be at least 1', 'delivery.invalid_assignment');
        }
    }
}
