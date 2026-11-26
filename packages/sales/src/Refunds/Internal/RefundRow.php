<?php

declare(strict_types=1);

namespace Commerz\Sales\Refunds\Internal;

use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Sales\Orders\Data\OrderId;
use Commerz\Sales\Payments\Data\PaymentId;
use Commerz\Sales\Refunds\Data\RefundId;
use Commerz\Sales\Refunds\Data\RefundView;
use Commerz\Sales\Refunds\Enums\RefundStatus;
use Commerz\Sales\Returns\Data\ReturnId;
use Commerz\Schema\Rows;
use DateTimeImmutable;
use Packman\Attributes\Internal;

/** A stored refund attempt; the amount in minor units of `$currency`. */
#[Internal]
final readonly class RefundRow
{
    public function __construct(
        public RefundId $id,
        public OrderId $order,
        public PaymentId $payment,
        public ?ReturnId $return,
        public string $gateway,
        public string $reference,
        public ?string $externalId,
        public RefundStatus $status,
        public Currency $currency,
        public int $amount,
        public ?string $reason,
        public int $attempts,
        public string $claimedAt,
        public ?string $errorCode,
        public ?string $errorMessage,
        public string $createdAt,
        public string $updatedAt,
    ) {}

    public static function fromRow(object $row): self
    {
        $return = Rows::nullableString($row, 'return_id');

        return new self(
            new RefundId(Rows::string($row, 'id')),
            new OrderId(Rows::string($row, 'order_id')),
            new PaymentId(Rows::string($row, 'payment_id')),
            $return === null ? null : new ReturnId($return),
            Rows::string($row, 'gateway'),
            Rows::string($row, 'reference'),
            Rows::nullableString($row, 'external_id'),
            RefundStatus::from(Rows::string($row, 'status')),
            Currency::of(Rows::string($row, 'currency')),
            Rows::int($row, 'amount_minor'),
            Rows::nullableString($row, 'reason'),
            Rows::int($row, 'attempts'),
            Rows::string($row, 'claimed_at'),
            Rows::nullableString($row, 'error_code'),
            Rows::nullableString($row, 'error_message'),
            Rows::string($row, 'created_at'),
            Rows::string($row, 'updated_at'),
        );
    }

    public function view(): RefundView
    {
        return new RefundView(
            $this->id,
            $this->order,
            $this->payment,
            $this->return,
            $this->gateway,
            $this->reference,
            $this->externalId,
            $this->status,
            Money::ofMinor($this->amount, $this->currency),
            $this->reason,
            $this->attempts,
            $this->errorCode,
            $this->errorMessage,
            new DateTimeImmutable($this->createdAt),
            new DateTimeImmutable($this->updatedAt),
        );
    }
}
