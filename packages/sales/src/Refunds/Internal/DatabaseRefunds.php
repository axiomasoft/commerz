<?php

declare(strict_types=1);

namespace Commerz\Sales\Refunds\Internal;

use Commerz\Operations\Contracts\Transactions;
use Commerz\Operations\Fingerprint;
use Commerz\Pagination\Page;
use Commerz\Sales\Common\Internal\OrderLocks;
use Commerz\Sales\Common\Internal\PaymentEventInbox;
use Commerz\Sales\Payments\Enums\PaymentEventOutcome;
use Commerz\Sales\Payments\Exceptions\InvalidPaymentEvent;
use Commerz\Sales\Refunds\Contracts\Refunds;
use Commerz\Sales\Refunds\Data\IssueRefund;
use Commerz\Sales\Refunds\Data\ListRefunds;
use Commerz\Sales\Refunds\Data\ReconcileRefund;
use Commerz\Sales\Refunds\Data\RecordRefundEvent;
use Commerz\Sales\Refunds\Data\RefundEventResult;
use Commerz\Sales\Refunds\Data\RefundId;
use Commerz\Sales\Refunds\Data\RefundView;
use Commerz\Sales\Refunds\Enums\RefundStatus;
use Commerz\Sales\Refunds\Exceptions\RefundNotFound;

/**
 * Refunds of orders (sales-design#returns-refunds): money only. Nothing here reserves, releases or restocks goods
 * (REF-01); a return of goods is `Returns::receive`.
 */
final readonly class DatabaseRefunds implements Refunds
{
    public function __construct(
        private Transactions $transactions,
        private OrderLocks $locks,
        private RefundJournal $journal,
        private RefundWorkflow $workflow,
        private PaymentEventInbox $inbox,
    ) {}

    public function issue(IssueRefund $command): RefundView
    {
        return $this->workflow->issue($command);
    }

    /**
     * PAY-01: order `for update` → the inbox claim → the transition, which locks the payment and the refund. A second
     * delivery of the event waits for the order, then finds the event claimed and changes nothing.
     */
    public function recordEvent(RecordRefundEvent $command): RefundEventResult
    {
        // Not found: nothing is recorded, so the provider's retry can succeed once the refund exists.
        $located = $this->journal->locate($command->gateway, $command->refundReference, $command->externalRefundId)
            ?? throw new RefundNotFound($command->refundReference ?? (string) $command->externalRefundId);

        $fingerprint = Fingerprint::of([
            'type' => $command->type(),
            'refund_reference' => $command->refundReference,
            'external_refund_id' => $command->externalRefundId,
        ]);

        return $this->transactions->run(function () use ($command, $located, $fingerprint): RefundEventResult {
            $order = $this->locks->lock($located->order);
            $refund = $this->journal->get($located->id);
            $this->assertFits($command, $refund);

            $stored = $this->inbox->claim(
                'sales.refund_event',
                $command->gateway,
                $command->externalEventId,
                $command->type(),
                $fingerprint,
                null,
                $refund->id->value,
                null,
                $command->occurredAt,
                $command->payload,
            );

            if ($stored !== null) {
                return new RefundEventResult($refund->id, true, PaymentEventOutcome::from($stored), $refund->status);
            }

            $observed = $command->succeeded ? RefundStatus::Succeeded : RefundStatus::Failed;
            $columns = $command->externalRefundId !== null && $refund->externalId === null ? ['external_id' => $command->externalRefundId] : [];

            if (RefundJournal::transition($refund->status, $observed) === null && $columns === []) {
                return new RefundEventResult($refund->id, false, PaymentEventOutcome::Ignored, $refund->status);
            }

            $columns += $command->succeeded ? [] : ['error_code' => 'refund_failed_at_gateway', 'error_message' => 'The provider reported the refund failed.'];
            $written = $this->journal->write($order, $refund, $observed, $columns);

            if ($written->status === $refund->status) {
                // A success the payment can no longer hold (refunded again meanwhile) leaves the refund failed.
                return new RefundEventResult($refund->id, false, PaymentEventOutcome::Ignored, $written->status);
            }

            $this->inbox->applied($command->gateway, $command->externalEventId);

            return new RefundEventResult($refund->id, false, PaymentEventOutcome::Applied, $written->status);
        });
    }

    public function reconcile(ReconcileRefund $command): RefundView
    {
        return $this->workflow->reconcile($command);
    }

    public function find(RefundId $id): RefundView
    {
        return $this->journal->get($id)->view();
    }

    public function list(ListRefunds $query): Page
    {
        return $this->journal->page($query);
    }

    /** @throws InvalidPaymentEvent */
    private function assertFits(RecordRefundEvent $event, RefundRow $refund): void
    {
        $external = $event->externalRefundId;

        if ($external !== null && $refund->externalId !== null && $external !== $refund->externalId) {
            throw new InvalidPaymentEvent('externalRefundId', 'differs from the provider id of the refund');
        }

        if ($external !== null && $refund->externalId === null && $this->journal->externalIdTaken($refund, $external)) {
            throw new InvalidPaymentEvent('externalRefundId', 'belongs to another refund');
        }
    }
}
