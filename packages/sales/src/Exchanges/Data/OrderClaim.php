<?php

declare(strict_types=1);

namespace Commerz\Sales\Exchanges\Data;

use Commerz\Money\Money;
use Commerz\Sales\Exchanges\Enums\ClaimKind;
use Commerz\Sales\Exchanges\Enums\ClaimStatus;
use Commerz\Sales\Fulfillments\Data\FulfillmentId;
use Commerz\Sales\Orders\Data\OrderId;
use Commerz\Sales\Returns\Enums\ReturnReason;
use DateTimeImmutable;
use Packman\Attributes\Api;

/**
 * Free replacement or reshipment claim for a fulfilled order with a zero invoice (`invoiceTotal = 0`, `amountDue = 0`).
 */
#[Api(since: '0.1')]
final readonly class OrderClaim
{
    /**
     * @param  list<ClaimLineView>  $lines
     */
    public function __construct(
        public ClaimId $id,
        public OrderId $order,
        public ClaimKind $kind,
        public ClaimStatus $status,
        public ?FulfillmentId $fulfillment,
        public ?ReturnReason $reason,
        public ?string $note,
        public array $lines,
        public Money $subtotal,
        public Money $taxTotal,
        public Money $invoiceTotal,
        public Money $amountDue,
        public ?DateTimeImmutable $fulfilledAt,
        public ?DateTimeImmutable $cancelledAt,
        public DateTimeImmutable $createdAt,
    ) {}
}
