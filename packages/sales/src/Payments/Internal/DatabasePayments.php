<?php

declare(strict_types=1);

namespace Commerz\Sales\Payments\Internal;

use Commerz\Operations\Contracts\Transactions;
use Commerz\Operations\Fingerprint;
use Commerz\Pagination\Page;
use Commerz\Sales\Common\Internal\OrderLocks;
use Commerz\Sales\Common\Internal\PaymentEventInbox;
use Commerz\Sales\Payments\Contracts\Payments;
use Commerz\Sales\Payments\Data\InitiatePayment;
use Commerz\Sales\Payments\Data\ListPayments;
use Commerz\Sales\Payments\Data\PaymentEventResult;
use Commerz\Sales\Payments\Data\PaymentId;
use Commerz\Sales\Payments\Data\PaymentView;
use Commerz\Sales\Payments\Data\ReconcilePayment;
use Commerz\Sales\Payments\Data\RecordPaymentEvent;
use Commerz\Sales\Payments\Enums\PaymentEventOutcome;
use Commerz\Sales\Payments\Exceptions\InvalidPaymentEvent;
use Commerz\Sales\Payments\Exceptions\PaymentNotFound;

final readonly class DatabasePayments implements Payments
{
    public function __construct(
        private Transactions $transactions,
        private OrderLocks $locks,
        private PaymentJournal $journal,
        private PaymentWorkflow $workflow,
        private PaymentEventInbox $inbox,
    ) {}

    public function initiate(InitiatePayment $command): PaymentView
    {
        return $this->workflow->initiate($command);
    }

    /**
     * PAY-01: order `for update` → payment `for update` → the inbox claim → the transition. A second delivery of the
     * event waits for the order, then finds the event claimed and changes nothing.
     */
    public function recordEvent(RecordPaymentEvent $command): PaymentEventResult
    {
        // Not found: nothing is recorded, so the provider's retry can succeed once the payment exists.
        $located = $this->journal->locate($command->gateway, $command->paymentReference, $command->externalPaymentId)
            ?? throw new PaymentNotFound($command->paymentReference ?? (string) $command->externalPaymentId);

        $fingerprint = Fingerprint::of([
            'type' => $command->type,
            'payment_reference' => $command->paymentReference,
            'external_payment_id' => $command->externalPaymentId,
            'amount' => $command->amount === null ? null : [$command->amount->amountMinor, $command->amount->currency->code],
        ]);

        return $this->transactions->run(function () use ($command, $located, $fingerprint): PaymentEventResult {
            $order = $this->locks->lock($located->order);
            $payment = $this->journal->lock($located->id);
            $this->assertFits($command, $payment);

            $stored = $this->inbox->claim(
                'sales.payment_event',
                $command->gateway,
                $command->externalEventId,
                $command->type->value,
                $fingerprint,
                $payment->id->value,
                null,
                $command->amount,
                $command->occurredAt,
                $command->payload,
            );

            if ($stored !== null) {
                return new PaymentEventResult($payment->id, true, PaymentEventOutcome::from($stored), $payment->status);
            }

            $next = PaymentTransitions::apply($payment, $command->type->status(), $command->amount?->amountMinor);
            $columns = $command->externalPaymentId !== null && $payment->externalId === null ? ['external_id' => $command->externalPaymentId] : [];

            if ($next === null && $columns === []) {
                return new PaymentEventResult($payment->id, false, PaymentEventOutcome::Ignored, $payment->status);
            }

            $written = $this->journal->write($order, $payment, $next ?? $payment, $columns);

            if ($next === null) {
                return new PaymentEventResult($payment->id, false, PaymentEventOutcome::Ignored, $written->status);
            }

            $this->inbox->applied($command->gateway, $command->externalEventId);

            return new PaymentEventResult($payment->id, false, PaymentEventOutcome::Applied, $written->status);
        });
    }

    public function reconcile(ReconcilePayment $command): PaymentView
    {
        return $this->workflow->reconcile($command);
    }

    public function find(PaymentId $id): PaymentView
    {
        return $this->journal->get($id->value)->view();
    }

    public function list(ListPayments $query): Page
    {
        return $this->journal->page($query);
    }

    /** @throws InvalidPaymentEvent */
    private function assertFits(RecordPaymentEvent $event, PaymentRow $payment): void
    {
        if ($event->amount !== null && ! $event->amount->currency->equals($payment->currency)) {
            throw new InvalidPaymentEvent('amount', "must be in {$payment->currency->code}, the currency of the payment");
        }

        if ($event->amount !== null && $event->amount->amountMinor > $payment->amount) {
            throw new InvalidPaymentEvent('amount', 'exceeds the payment');
        }

        $external = $event->externalPaymentId;

        if ($external !== null && $payment->externalId !== null && $external !== $payment->externalId) {
            throw new InvalidPaymentEvent('externalPaymentId', 'differs from the provider id of the payment');
        }

        if ($external !== null && $payment->externalId === null && $this->journal->externalIdTaken($payment, $external)) {
            throw new InvalidPaymentEvent('externalPaymentId', 'belongs to another payment');
        }
    }
}
