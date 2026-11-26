<?php

declare(strict_types=1);

namespace Commerz\Sales\Fulfillments\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Identity\ActorRef;
use Commerz\Identity\IdempotencyKey;
use Commerz\Ports\Delivery\Data\FulfillmentConstraints;
use Commerz\Ports\Stock\Data\StockLines;
use Commerz\Sales\Common\Data\LineQuantity;
use Commerz\Sales\Orders\Data\OrderId;
use Packman\Attributes\Api;

/** Assigns lines of a placed order to a warehouse as a {@see FulfillmentOrder}. */
#[Api(since: '0.1')]
final readonly class CreateFulfillmentOrder
{
    /** @param  non-empty-list<LineQuantity>  $lines  one entry per order line */
    public function __construct(
        public OrderId $order,
        public string $locationCode,
        public array $lines,
        public FulfillmentConstraints $constraints = new FulfillmentConstraints,
        public ?IdempotencyKey $idempotencyKey = null,
        public ?ActorRef $actor = null,
    ) {
        if ($locationCode === '') {
            throw new InvalidCommand('locationCode', 'must not be empty', 'sales.invalid_fulfillment');
        }

        StockLines::assertLocation($locationCode);

        $ids = array_map(static fn (LineQuantity $line): string => $line->lineId->value, $lines);

        if (count(array_unique($ids)) !== count($ids)) {
            throw new InvalidCommand('lines', 'must name each line once', 'sales.invalid_lines');
        }
    }
}
