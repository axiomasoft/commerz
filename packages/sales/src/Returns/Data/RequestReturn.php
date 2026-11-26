<?php

declare(strict_types=1);

namespace Commerz\Sales\Returns\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Identity\ActorRef;
use Commerz\Identity\IdempotencyKey;
use Commerz\Sales\Common\Data\LineQuantity;
use Commerz\Sales\Orders\Data\OrderId;
use Commerz\Sales\Returns\Enums\ReturnReason;
use Packman\Attributes\Api;

/** Asks to return fulfilled units of the lines of an order; no stock moves until the return is received. */
#[Api(since: '0.1')]
final readonly class RequestReturn
{
    public ?string $reason;

    public ?ReturnReason $returnReason;

    /** @param  list<LineQuantity>  $lines  one entry per line */
    public function __construct(
        public OrderId $order,
        public array $lines,
        ReturnReason|string|null $reason = null,
        public ?IdempotencyKey $idempotencyKey = null,
        public ?ActorRef $actor = null,
        ?ReturnReason $returnReason = null,
    ) {
        if ($lines === []) {
            throw new InvalidCommand('lines', 'must not be empty', 'sales.invalid_lines');
        }

        $ids = array_map(static fn (LineQuantity $line): string => $line->lineId->value, $lines);

        if (count(array_unique($ids)) !== count($ids)) {
            throw new InvalidCommand('lines', 'must name each line once', 'sales.invalid_lines');
        }

        if ($reason instanceof ReturnReason) {
            $resolvedText = $reason->value;
            $resolvedReason = $returnReason ?? $reason;
        } else {
            $resolvedText = $reason;
            $resolvedReason = $returnReason ?? ($reason !== null ? ReturnReason::tryFrom($reason) : null);
        }

        if ($resolvedText !== null && (trim($resolvedText) === '' || mb_strlen($resolvedText) > 191 || str_contains($resolvedText, "\0") || ! mb_check_encoding($resolvedText, 'UTF-8'))) {
            throw new InvalidCommand('reason', 'must be a non-empty UTF-8 text of at most 191 characters without NUL', 'sales.invalid_return');
        }

        $this->reason = $resolvedText;
        $this->returnReason = $resolvedReason;
    }
}
