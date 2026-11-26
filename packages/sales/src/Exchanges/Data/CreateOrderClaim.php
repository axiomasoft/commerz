<?php

declare(strict_types=1);

namespace Commerz\Sales\Exchanges\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Identity\ActorRef;
use Commerz\Identity\IdempotencyKey;
use Commerz\Ports\Stock\Data\StockLines;
use Commerz\Sales\Exchanges\Enums\ClaimKind;
use Commerz\Sales\Orders\Data\OrderId;
use Commerz\Sales\Orders\Data\OrderLineId;
use Commerz\Sales\Returns\Enums\ReturnReason;
use Packman\Attributes\Api;

/**
 * Creates a free replacement or reshipment {@see OrderClaim} with a zero invoice (`invoiceTotal = 0`, `amountDue = 0`).
 * When `$fulfillImmediately` is true, creates the outbound fulfillment and consumes stock in the same transaction.
 */
#[Api(since: '0.1')]
final readonly class CreateOrderClaim
{
    /**
     * @param  list<ClaimItem>  $items
     */
    public function __construct(
        public OrderId $order,
        public array $items,
        public ClaimKind $kind = ClaimKind::Replacement,
        public ?ReturnReason $reason = null,
        public ?string $note = null,
        public bool $fulfillImmediately = true,
        public ?string $locationCode = null,
        public ?IdempotencyKey $idempotencyKey = null,
        public ?ActorRef $actor = null,
    ) {
        if ($items === []) {
            throw new InvalidCommand('items', 'must not be empty', 'sales.invalid_lines');
        }

        $seen = [];

        foreach ($items as $item) {
            $key = $item->target instanceof OrderLineId
                ? 'line:'.$item->target->value
                : 'sellable:'.(string) $item->target;

            if (isset($seen[$key])) {
                throw new InvalidCommand('items', "'{$key}' appears more than once", 'sales.invalid_lines');
            }

            $seen[$key] = true;
        }

        StockLines::assertLocation($locationCode);

        if ($note !== null && (trim($note) === '' || mb_strlen($note) > 191 || str_contains($note, "\0") || ! mb_check_encoding($note, 'UTF-8'))) {
            throw new InvalidCommand('note', 'must be a non-empty UTF-8 text of at most 191 characters without NUL', 'sales.invalid_claim');
        }
    }
}
