<?php

declare(strict_types=1);

namespace Commerz\Sales\Payments\Internal;

use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Sales\Orders\Data\OrderId;
use Commerz\Sales\Payments\Data\PaymentId;
use Commerz\Sales\Payments\Data\PaymentView;
use Commerz\Sales\Payments\Enums\PaymentStatus;
use Commerz\Schema\Rows;
use DateTimeImmutable;
use Packman\Attributes\Internal;
use RuntimeException;

/** A stored payment attempt; amounts in minor units of `$currency`. */
#[Internal]
final readonly class PaymentRow
{
    /** @param  array<string, scalar>  $meta */
    public function __construct(
        public PaymentId $id,
        public OrderId $order,
        public string $gateway,
        public string $reference,
        public ?string $externalId,
        public PaymentStatus $status,
        public Currency $currency,
        public int $amount,
        public int $authorized = 0,
        public int $captured = 0,
        public int $refunded = 0,
        public ?string $actionUrl = null,
        public array $meta = [],
        public int $attempts = 1,
        public string $claimedAt = '',
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
        public string $createdAt = '',
        public string $updatedAt = '',
    ) {}

    public static function fromRow(object $row): self
    {
        $meta = [];

        foreach (Rows::json($row, 'meta') as $key => $value) {
            $meta[(string) $key] = is_scalar($value) ? $value : throw new RuntimeException("Payment meta '{$key}' is not a scalar.");
        }

        return new self(
            new PaymentId(Rows::string($row, 'id')),
            new OrderId(Rows::string($row, 'order_id')),
            Rows::string($row, 'gateway'),
            Rows::string($row, 'reference'),
            Rows::nullableString($row, 'external_id'),
            PaymentStatus::from(Rows::string($row, 'status')),
            Currency::of(Rows::string($row, 'currency')),
            Rows::int($row, 'amount_minor'),
            Rows::int($row, 'authorized_minor'),
            Rows::int($row, 'captured_minor'),
            Rows::int($row, 'refunded_minor'),
            Rows::nullableString($row, 'action_url'),
            $meta,
            Rows::int($row, 'attempts'),
            Rows::string($row, 'claimed_at'),
            Rows::nullableString($row, 'error_code'),
            Rows::nullableString($row, 'error_message'),
            Rows::string($row, 'created_at'),
            Rows::string($row, 'updated_at'),
        );
    }

    /** The same payment with another status and money; what the transitions change, nothing else. */
    public function with(PaymentStatus $status, int $authorized, int $captured, ?string $actionUrl): self
    {
        return new self(
            $this->id, $this->order, $this->gateway, $this->reference, $this->externalId, $status, $this->currency, $this->amount,
            $authorized, $captured, $this->refunded, $actionUrl, $this->meta, $this->attempts, $this->claimedAt,
            $this->errorCode, $this->errorMessage, $this->createdAt, $this->updatedAt,
        );
    }

    public function view(): PaymentView
    {
        return new PaymentView(
            $this->id,
            $this->order,
            $this->gateway,
            $this->reference,
            $this->externalId,
            $this->status,
            Money::ofMinor($this->amount, $this->currency),
            Money::ofMinor($this->authorized, $this->currency),
            Money::ofMinor($this->captured, $this->currency),
            Money::ofMinor($this->refunded, $this->currency),
            $this->actionUrl,
            $this->meta,
            $this->attempts,
            $this->errorCode,
            $this->errorMessage,
            new DateTimeImmutable($this->createdAt),
            new DateTimeImmutable($this->updatedAt),
        );
    }
}
