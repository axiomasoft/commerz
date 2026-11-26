<?php

declare(strict_types=1);

namespace Commerz\Sales\Exchanges\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Identity\ActorRef;
use Commerz\Identity\IdempotencyKey;
use Commerz\Sales\Common\Data\LineQuantity;
use Commerz\Sales\Orders\Data\OrderId;
use Commerz\Sales\Returns\Data\ReturnView;
use Commerz\Sales\Returns\Enums\ReturnReason;
use Packman\Attributes\Api;

/**
 * Opens a two-way exchange on a fulfilled order: requests an inbound {@see ReturnView},
 * reserves stock for outbound replacement items, and computes the exact net financial delta (`outboundTotal - returnTotal`).
 */
#[Api(since: '0.1')]
final readonly class CreateOrderExchange
{
    /**
     * @param  list<LineQuantity>  $inboundLines  fulfilled order lines being returned
     * @param  list<ExchangeOutboundItem>  $outboundItems  replacement sellables being sent out
     */
    public function __construct(
        public OrderId $order,
        public array $inboundLines,
        public array $outboundItems,
        public ?ReturnReason $reason = null,
        public ?string $note = null,
        public ?IdempotencyKey $idempotencyKey = null,
        public ?ActorRef $actor = null,
    ) {
        if ($inboundLines === []) {
            throw new InvalidCommand('inboundLines', 'must not be empty', 'sales.invalid_lines');
        }

        $inboundIds = array_map(static fn (LineQuantity $line): string => $line->lineId->value, $inboundLines);

        if (count(array_unique($inboundIds)) !== count($inboundIds)) {
            throw new InvalidCommand('inboundLines', 'must name each order line once', 'sales.invalid_lines');
        }

        if ($outboundItems === []) {
            throw new InvalidCommand('outboundItems', 'must not be empty', 'sales.invalid_lines');
        }

        $outboundRefs = array_map(static fn (ExchangeOutboundItem $item): string => (string) $item->sellable, $outboundItems);

        if (count(array_unique($outboundRefs)) !== count($outboundRefs)) {
            throw new InvalidCommand('outboundItems', 'must name each outbound sellable once', 'sales.invalid_lines');
        }

        if ($note !== null && (trim($note) === '' || mb_strlen($note) > 191 || str_contains($note, "\0") || ! mb_check_encoding($note, 'UTF-8'))) {
            throw new InvalidCommand('note', 'must be a non-empty UTF-8 text of at most 191 characters without NUL', 'sales.invalid_exchange');
        }
    }
}
