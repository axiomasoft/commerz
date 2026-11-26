<?php

declare(strict_types=1);

namespace Commerz\Sales\OrderChanges\Internal;

use Commerz\Errors\InvalidCommand;
use Commerz\Identity\ActorRef;
use Commerz\Identity\IdempotencyKey;
use Commerz\Money\Money;
use Commerz\Ports\StoredValue\Contracts\StoredValueTender;
use Commerz\Ports\StoredValue\Data\RefundToStoredValue;
use Commerz\Sales\OrderChanges\Contracts\OrderChangeCompensator;
use Commerz\Sales\OrderChanges\Data\CompensationOutcome;
use Commerz\Sales\OrderChanges\Data\CompensationPreference;
use Commerz\Sales\OrderChanges\Data\OrderChangeId;
use Commerz\Sales\OrderChanges\Enums\CompensationStrategy;
use Commerz\Sales\OrderChanges\Enums\NegativeDeltaStrategy;
use Commerz\Sales\OrderChanges\Enums\PositiveDeltaStrategy;
use Commerz\Sales\Orders\Data\OrderView;
use Commerz\Sales\Payments\Contracts\Payments;
use Commerz\Sales\Payments\Data\InitiatePayment;
use Commerz\Sales\Payments\Data\ListPayments;
use Commerz\Sales\Payments\Data\PaymentView;
use Commerz\Sales\Payments\Enums\PaymentStatus;
use Commerz\Sales\Refunds\Contracts\Refunds;
use Commerz\Sales\Refunds\Data\IssueRefund;
use Override;
use Packman\Attributes\Internal;

/**
 * Default financial compensator for confirmed `OrderChange` deltas:
 * - positive delta -> incremental authorization attempt or new delta charge via `Payments::initiate()`
 * - negative delta (overpaid) -> partial refund via `Refunds::issue()` or store credit via `StoredValueTender::refundCredit()`
 */
#[Internal]
final readonly class DefaultOrderChangeCompensator implements OrderChangeCompensator
{
    public function __construct(
        private Payments $payments,
        private Refunds $refunds,
        private StoredValueTender $storedValue,
    ) {}

    #[Override]
    public function compensateIncrease(
        OrderChangeId $changeId,
        OrderView $order,
        Money $delta,
        CompensationPreference $preference,
        ?IdempotencyKey $idempotencyKey = null,
        ?ActorRef $actor = null,
    ): CompensationOutcome {
        if (! $delta->isPositive()) {
            return new CompensationOutcome(CompensationStrategy::None, Money::zero($order->currency));
        }

        if ($preference->onIncrease === PositiveDeltaStrategy::Deferred) {
            return new CompensationOutcome(CompensationStrategy::Deferred, $delta);
        }

        $existing = $this->payments->list(new ListPayments(order: $order->id, limit: 100))->items;
        $targetAuth = $this->findAuthorizedPayment($existing, $preference);
        $fallbackGateway = $existing !== [] ? $existing[0]->gateway : null;
        $gateway = $preference->paymentGateway ?? ($targetAuth !== null ? $targetAuth->gateway : $fallbackGateway);

        if ($gateway === null) {
            return new CompensationOutcome(
                strategy: CompensationStrategy::Deferred,
                amount: $delta,
                details: ['reason' => 'no_payment_gateway'],
            );
        }

        $strategy = $preference->onIncrease === PositiveDeltaStrategy::IncrementAuthorization && $targetAuth !== null
            ? CompensationStrategy::IncrementAuthorization
            : CompensationStrategy::DeltaCharge;

        $meta = $preference->paymentMeta + [
            'order_change_id' => $changeId->value,
            'compensation_strategy' => $strategy->value,
        ];

        if ($targetAuth !== null) {
            $meta['parent_payment_id'] = $targetAuth->id->value;
        }

        $key = $this->deriveKey($changeId, 'increase', $idempotencyKey);
        $payment = $this->payments->initiate(new InitiatePayment(
            order: $order->id,
            gateway: $gateway,
            amount: $delta,
            meta: $meta,
            idempotencyKey: $key,
            actor: $actor,
        ));

        return new CompensationOutcome(
            strategy: $strategy,
            amount: $delta,
            paymentId: $payment->id,
            details: [
                'gateway' => $payment->gateway,
                'payment_status' => $payment->status->value,
                'parent_payment_id' => $targetAuth?->id->value,
            ],
        );
    }

    #[Override]
    public function compensateDecrease(
        OrderChangeId $changeId,
        OrderView $order,
        Money $overpaid,
        CompensationPreference $preference,
        ?IdempotencyKey $idempotencyKey = null,
        ?ActorRef $actor = null,
    ): CompensationOutcome {
        if (! $overpaid->isPositive()) {
            return new CompensationOutcome(CompensationStrategy::None, Money::zero($order->currency));
        }

        if ($preference->onDecrease === NegativeDeltaStrategy::Deferred) {
            return new CompensationOutcome(CompensationStrategy::Deferred, $overpaid);
        }

        if ($preference->onDecrease === NegativeDeltaStrategy::WalletCredit) {
            if ($preference->storedValueAccountId === null && $order->customer === null) {
                throw new InvalidCommand('storedValueAccountId', 'wallet credit requires either storedValueAccountId or an order customer', 'sales.invalid_order_change');
            }

            $credit = $this->storedValue->refundCredit(new RefundToStoredValue(
                amount: $overpaid,
                reference: 'order_change:'.$changeId->value,
                idempotencyKey: $this->deriveKey($changeId, 'wallet_credit', $idempotencyKey)->value,
                accountId: $preference->storedValueAccountId,
                customer: $order->customer,
                actorRef: $actor === null ? null : (string) $actor,
                reason: $preference->reason ?? 'Order change store credit',
            ));

            return new CompensationOutcome(
                strategy: CompensationStrategy::WalletCredit,
                amount: $overpaid,
                storedValueAccountId: $credit->accountId,
                storedValueJournalId: $credit->journalId,
                details: ['balance_after_minor' => $credit->balanceAfter->amountMinor],
            );
        }

        $refund = $this->refunds->issue(new IssueRefund(
            order: $order->id,
            amount: $overpaid,
            payment: $preference->paymentId,
            return: null,
            reason: $preference->reason ?? 'Order change refund',
            idempotencyKey: $this->deriveKey($changeId, 'refund', $idempotencyKey),
            actor: $actor,
        ));

        return new CompensationOutcome(
            strategy: CompensationStrategy::PartialRefund,
            amount: $overpaid,
            paymentId: $refund->payment,
            refundId: $refund->id,
            details: [
                'gateway' => $refund->gateway,
                'refund_status' => $refund->status->value,
            ],
        );
    }

    /** @param  list<PaymentView>  $payments */
    private function findAuthorizedPayment(array $payments, CompensationPreference $preference): ?PaymentView
    {
        if ($preference->paymentId !== null) {
            foreach ($payments as $payment) {
                if ($payment->id->equals($preference->paymentId) && $payment->status === PaymentStatus::Authorized) {
                    return $payment;
                }
            }

            return null;
        }

        foreach ($payments as $payment) {
            if ($payment->status === PaymentStatus::Authorized) {
                return $payment;
            }
        }

        return null;
    }

    private function deriveKey(OrderChangeId $changeId, string $suffix, ?IdempotencyKey $callerKey): IdempotencyKey
    {
        $base = $callerKey === null
            ? "order_change:{$changeId->value}:{$suffix}"
            : "order_change:{$changeId->value}:{$suffix}:".substr(hash('sha256', $callerKey->value), 0, 16);

        return IdempotencyKey::of($base);
    }
}
