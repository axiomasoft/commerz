<?php

declare(strict_types=1);

namespace Commerz\Sales\Orders\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Identity\ActorRef;
use Commerz\Sales\Common\Data\LineQuantity;
use Commerz\Sales\Orders\Enums\CancelReason;
use Packman\Attributes\Api;

/** Cancels units of some lines of a placed order; cancelling every unit left cancels the order. */
#[Api(since: '0.1')]
final readonly class CancelOrderLines
{
    /** @param  list<LineQuantity>  $lines  one entry per line */
    public function __construct(
        public OrderId $order,
        public array $lines,
        public CancelReason $reason,
        public ?ActorRef $actor = null,
    ) {
        if ($lines === []) {
            throw new InvalidCommand('lines', 'must not be empty', 'sales.invalid_lines');
        }

        $ids = array_map(static fn (LineQuantity $line): string => $line->lineId->value, $lines);

        if (count(array_unique($ids)) !== count($ids)) {
            throw new InvalidCommand('lines', 'must name each line once', 'sales.invalid_lines');
        }
    }
}
