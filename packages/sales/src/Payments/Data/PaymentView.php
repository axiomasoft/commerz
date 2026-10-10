<?php

declare(strict_types=1);

namespace Commerz\Sales\Payments\Data;

use Commerz\Attributes\Api;
use Commerz\Money\Money;
use Commerz\Sales\Orders\Data\OrderId;
use Commerz\Sales\Payments\Enums\PaymentStatus;
use DateTimeImmutable;

/** One payment attempt of an order. */
#[Api(since: '0.1')]
final readonly class PaymentView
{
    /**
     * @param  string  $reference  `payment:{id}`, the idempotency key at the gateway
     * @param  string|null  $externalId  the provider's id of the payment, once known
     * @param  string|null  $actionUrl  where the customer continues (`requires_action`)
     * @param  array<string, scalar>  $meta  what the caller passed, then what the gateway answered
     * @param  int  $attempts  how many times the gateway was asked (initiate, then each reconcile)
     */
    public function __construct(
        public PaymentId $id,
        public OrderId $order,
        public string $gateway,
        public string $reference,
        public ?string $externalId,
        public PaymentStatus $status,
        public Money $amount,
        public Money $authorized,
        public Money $captured,
        public Money $refunded,
        public ?string $actionUrl,
        public array $meta,
        public int $attempts,
        public ?string $errorCode,
        public ?string $errorMessage,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}
}
