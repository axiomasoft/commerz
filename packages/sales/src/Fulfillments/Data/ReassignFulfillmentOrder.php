<?php

declare(strict_types=1);

namespace Commerz\Sales\Fulfillments\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Identity\ActorRef;
use Commerz\Ports\Stock\Data\StockLines;
use Packman\Attributes\Api;

/** Moves an open or held {@see FulfillmentOrder} to another warehouse location. */
#[Api(since: '0.1')]
final readonly class ReassignFulfillmentOrder
{
    public function __construct(
        public FulfillmentOrderId $fulfillmentOrder,
        public string $locationCode,
        public ?ActorRef $actor = null,
    ) {
        if ($locationCode === '') {
            throw new InvalidCommand('locationCode', 'must not be empty', 'sales.invalid_fulfillment');
        }

        StockLines::assertLocation($locationCode);
    }
}
