<?php

declare(strict_types=1);

namespace Commerz\Sales\Payments\Data;

use Commerz\Identity\CustomerRef;
use Commerz\Money\Money;
use Commerz\Sales\Orders\Data\OrderId;
use Commerz\Sales\Payments\Enums\PaymentCollectionKind;
use Commerz\Sales\Payments\Enums\PaymentCollectionStatus;
use DateTimeImmutable;
use Packman\Attributes\Api;

/**
 * Split-tender payment collection grouping one or more {@see PaymentSessionView} sessions (e.g. stored value + card
 * gateway) toward an order or top-up target.
 */
#[Api(since: '0.1')]
final readonly class PaymentCollectionView
{
    /**
     * @param  list<PaymentSessionView>  $sessions
     * @param  array<string, scalar>  $meta
     */
    public function __construct(
        public PaymentCollectionId $id,
        public PaymentCollectionKind $kind,
        public string $targetRef,
        public ?OrderId $order,
        public PaymentCollectionStatus $status,
        public Money $amount,
        public Money $authorized,
        public Money $captured,
        public ?CustomerRef $customer,
        public ?string $customerEmail,
        public array $meta,
        public array $sessions,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}

    /** Remaining target amount not yet covered by captured funds or active open authorizations. */
    public function remainingToAuthorize(): Money
    {
        $coveredMinor = $this->captured->amountMinor + $this->authorized->amountMinor;

        return Money::ofMinor(max(0, $this->amount->amountMinor - $coveredMinor), $this->amount->currency);
    }

    /** Remaining target amount not yet captured. */
    public function remainingToCapture(): Money
    {
        return Money::ofMinor(max(0, $this->amount->amountMinor - $this->captured->amountMinor), $this->amount->currency);
    }

    public function session(PaymentSessionId $id): ?PaymentSessionView
    {
        return array_find($this->sessions, static fn (PaymentSessionView $session): bool => $session->id->equals($id));
    }
}
