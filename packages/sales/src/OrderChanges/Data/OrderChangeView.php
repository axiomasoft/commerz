<?php

declare(strict_types=1);

namespace Commerz\Sales\OrderChanges\Data;

use Commerz\Identity\ActorRef;
use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Sales\OrderChanges\Enums\OrderChangeStatus;
use Commerz\Sales\Orders\Data\OrderId;
use DateTimeImmutable;
use Packman\Attributes\Api;

/** Read model of an OrderChange and its queued actions. */
#[Api(since: '0.1')]
final readonly class OrderChangeView
{
    /** @param  list<OrderChangeActionView>  $actions */
    public function __construct(
        public OrderChangeId $id,
        public OrderId $orderId,
        public OrderChangeStatus $status,
        public int $baseOrderVersion,
        public ?int $confirmedOrderVersion,
        public Currency $currency,
        public Money $amountDelta,
        public Money $taxDelta,
        public ?string $description,
        public array $actions,
        public ?CompensationOutcome $compensation,
        public ?ActorRef $createdBy,
        public ?ActorRef $confirmedBy,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $confirmedAt,
        public ?DateTimeImmutable $cancelledAt,
    ) {}
}
