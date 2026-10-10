<?php

declare(strict_types=1);

namespace Commerz\Sales\Fulfillments\Data;

use Commerz\Attributes\Api;
use Commerz\Errors\InvalidCommand;
use Commerz\Identity\ActorRef;
use Commerz\Identity\IdempotencyKey;
use Commerz\Ports\Stock\Data\StockLines;
use Commerz\Sales\Common\Data\LineQuantity;
use Commerz\Sales\Orders\Data\OrderId;

/**
 * Fulfils units of lines of a placed order: the units leave the ledger's outstanding, and the held stock of the
 * physical lines is consumed from `$locationCode` (the stock decides when null).
 */
#[Api(since: '0.1')]
final readonly class CreateFulfillment
{
    /** @param  list<LineQuantity>  $lines  one entry per line */
    public function __construct(
        public OrderId $order,
        public array $lines,
        public ?string $locationCode = null,
        public ?IdempotencyKey $idempotencyKey = null,
        public ?ActorRef $actor = null,
    ) {
        if ($lines === []) {
            throw new InvalidCommand('lines', 'must not be empty', 'sales.invalid_lines');
        }

        $ids = array_map(static fn (LineQuantity $line): string => $line->lineId->value, $lines);

        if (count(array_unique($ids)) !== count($ids)) {
            throw new InvalidCommand('lines', 'must name each line once', 'sales.invalid_lines');
        }

        StockLines::assertLocation($locationCode);
    }
}
