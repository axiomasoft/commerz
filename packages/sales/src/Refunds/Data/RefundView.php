<?php

declare(strict_types=1);

namespace Commerz\Sales\Refunds\Data;

use Commerz\Attributes\Api;
use Commerz\Money\Money;
use Commerz\Sales\Orders\Data\OrderId;
use Commerz\Sales\Payments\Data\PaymentId;
use Commerz\Sales\Refunds\Enums\RefundStatus;
use Commerz\Sales\Returns\Data\ReturnId;
use DateTimeImmutable;

/** One refund attempt against a payment of an order. */
#[Api(since: '0.1')]
final readonly class RefundView
{
    /**
     * @param  string  $gateway  the gateway of the payment, which makes the refund
     * @param  string  $reference  `refund:{id}`, the idempotency key at the gateway
     * @param  string|null  $externalId  the provider's id of the refund, once known
     * @param  int  $attempts  how many times the gateway was asked (issue, then each reconcile)
     */
    public function __construct(
        public RefundId $id,
        public OrderId $order,
        public PaymentId $payment,
        public ?ReturnId $return,
        public string $gateway,
        public string $reference,
        public ?string $externalId,
        public RefundStatus $status,
        public Money $amount,
        public ?string $reason,
        public int $attempts,
        public ?string $errorCode,
        public ?string $errorMessage,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}
}
