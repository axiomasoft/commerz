<?php

declare(strict_types=1);

namespace Commerz\Sales\Payments\Internal;

use Commerz\Identity\CustomerRef;
use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Sales\Orders\Data\OrderId;
use Commerz\Sales\Payments\Data\PaymentCollectionId;
use Commerz\Sales\Payments\Data\PaymentCollectionView;
use Commerz\Sales\Payments\Data\PaymentSessionView;
use Commerz\Sales\Payments\Enums\PaymentCollectionKind;
use Commerz\Sales\Payments\Enums\PaymentCollectionStatus;
use Commerz\Schema\Rows;
use DateTimeImmutable;
use Packman\Attributes\Internal;
use RuntimeException;

#[Internal]
final readonly class PaymentCollectionRow
{
    /** @param  array<string, scalar>  $meta */
    public function __construct(
        public PaymentCollectionId $id,
        public PaymentCollectionKind $kind,
        public string $targetRef,
        public ?OrderId $order,
        public PaymentCollectionStatus $status,
        public Currency $currency,
        public int $amount,
        public int $authorized = 0,
        public int $captured = 0,
        public ?CustomerRef $customer = null,
        public ?string $customerEmail = null,
        public array $meta = [],
        public string $createdAt = '',
        public string $updatedAt = '',
    ) {}

    public static function fromRow(object $row): self
    {
        $meta = [];

        foreach (Rows::json($row, 'meta') as $key => $value) {
            $meta[(string) $key] = is_scalar($value) ? $value : throw new RuntimeException("Collection meta '{$key}' is not a scalar.");
        }

        $orderId = Rows::nullableString($row, 'order_id');
        $customerRef = Rows::nullableString($row, 'customer_ref');

        return new self(
            new PaymentCollectionId(Rows::string($row, 'id')),
            PaymentCollectionKind::from(Rows::string($row, 'kind')),
            Rows::string($row, 'target_ref'),
            $orderId === null ? null : new OrderId($orderId),
            PaymentCollectionStatus::from(Rows::string($row, 'status')),
            Currency::of(Rows::string($row, 'currency')),
            Rows::int($row, 'amount_minor'),
            Rows::int($row, 'authorized_minor'),
            Rows::int($row, 'captured_minor'),
            $customerRef === null ? null : CustomerRef::fromString($customerRef),
            Rows::nullableString($row, 'customer_email'),
            $meta,
            Rows::string($row, 'created_at'),
            Rows::string($row, 'updated_at'),
        );
    }

    /** @param  list<PaymentSessionView>  $sessions */
    public function view(array $sessions): PaymentCollectionView
    {
        return new PaymentCollectionView(
            $this->id,
            $this->kind,
            $this->targetRef,
            $this->order,
            $this->status,
            Money::ofMinor($this->amount, $this->currency),
            Money::ofMinor($this->authorized, $this->currency),
            Money::ofMinor($this->captured, $this->currency),
            $this->customer,
            $this->customerEmail,
            $this->meta,
            $sessions,
            new DateTimeImmutable($this->createdAt),
            new DateTimeImmutable($this->updatedAt),
        );
    }
}
