<?php

declare(strict_types=1);

namespace Commerz\Sales\Returns\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Identity\ActorRef;
use Commerz\Identity\IdempotencyKey;
use Commerz\Sales\Common\Data\LineQuantity;
use Commerz\Sales\Orders\Data\OrderId;
use Packman\Attributes\Api;

/** Asks to return fulfilled units of the lines of an order; no stock moves until the return is received. */
#[Api(since: '0.1')]
final readonly class RequestReturn
{
    /** @param  list<LineQuantity>  $lines  one entry per line */
    public function __construct(
        public OrderId $order,
        public array $lines,
        public ?string $reason = null,
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

        if ($reason !== null && (trim($reason) === '' || mb_strlen($reason) > 191 || str_contains($reason, "\0") || ! mb_check_encoding($reason, 'UTF-8'))) {
            throw new InvalidCommand('reason', 'must be a non-empty UTF-8 text of at most 191 characters without NUL', 'sales.invalid_return');
        }
    }
}
