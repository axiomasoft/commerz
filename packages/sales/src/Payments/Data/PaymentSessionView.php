<?php

declare(strict_types=1);

namespace Commerz\Sales\Payments\Data;

use Commerz\Money\Money;
use Commerz\Ports\StoredValue\Enums\StoredValueKind;
use Commerz\Sales\Payments\Enums\PaymentSessionKind;
use Commerz\Sales\Payments\Enums\PaymentSessionStatus;
use DateTimeImmutable;
use Packman\Attributes\Api;

/** One tender session (gateway or stored value) inside a {@see PaymentCollectionView}. */
#[Api(since: '0.1')]
final readonly class PaymentSessionView
{
    /**
     * @param  list<PaymentCaptureView>  $captures
     * @param  array<string, scalar>  $meta
     */
    public function __construct(
        public PaymentSessionId $id,
        public PaymentCollectionId $collection,
        public PaymentSessionKind $kind,
        public ?string $gateway,
        public ?StoredValueKind $storedValueKind,
        public ?string $storedValueAccountId,
        public ?string $storedValueHoldId,
        public string $reference,
        public ?string $externalId,
        public PaymentSessionStatus $status,
        public Money $amount,
        public Money $authorized,
        public Money $captured,
        public bool $captureClosed,
        public ?string $actionUrl,
        public array $meta,
        public int $attempts,
        public ?string $errorCode,
        public ?string $errorMessage,
        public array $captures,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}

    public function remainingCapturable(): Money
    {
        if ($this->captureClosed || ! $this->status->isActive()) {
            return Money::zero($this->amount->currency);
        }

        $remainingMinor = max(0, $this->authorized->amountMinor - $this->captured->amountMinor);

        return Money::ofMinor($remainingMinor, $this->amount->currency);
    }
}
