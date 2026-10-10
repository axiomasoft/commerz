<?php

declare(strict_types=1);

namespace Commerz\Sales\Payments\Contracts;

use Commerz\Attributes\Api;
use Commerz\Errors\IdempotencyConflict;
use Commerz\Errors\InvalidCommand;
use Commerz\Errors\UnsupportedContribution;
use Commerz\Pagination\Page;
use Commerz\Sales\Common\Exceptions\OperationInsideTransaction;
use Commerz\Sales\Gateways\Exceptions\InvalidGatewayResult;
use Commerz\Sales\Gateways\Exceptions\PaymentDeclined;
use Commerz\Sales\Orders\Exceptions\OrderNotFound;
use Commerz\Sales\Payments\Data\InitiatePayment;
use Commerz\Sales\Payments\Data\ListPayments;
use Commerz\Sales\Payments\Data\PaymentEventResult;
use Commerz\Sales\Payments\Data\PaymentId;
use Commerz\Sales\Payments\Data\PaymentView;
use Commerz\Sales\Payments\Data\ReconcilePayment;
use Commerz\Sales\Payments\Data\RecordPaymentEvent;
use Commerz\Sales\Payments\Exceptions\InvalidPaymentEvent;
use Commerz\Sales\Payments\Exceptions\OrderNotPayable;
use Commerz\Sales\Payments\Exceptions\PaymentExceedsBalance;
use Commerz\Sales\Payments\Exceptions\PaymentInProgress;
use Commerz\Sales\Payments\Exceptions\PaymentNotFound;
use Commerz\Sales\Payments\Exceptions\PaymentOutcomeUnknown;

/**
 * Payments of orders at payment gateways (PAY-01, PAY-02, D33). An attempt is written before the gateway is called,
 * the gateway is called outside any database transaction, and an answer that is not certain leaves the attempt
 * `unknown` until `reconcile` asks the gateway. Money is never captured twice: provider events are recorded once per
 * id, and the status of a payment only moves forward.
 */
#[Api(since: '0.1')]
interface Payments
{
    /**
     * Starts a payment attempt at the gateway; must be called outside any database transaction. A repeat with the same
     * idempotency key returns the attempt as it is now, without calling the gateway again.
     *
     * @throws OperationInsideTransaction
     * @throws UnsupportedContribution no gateway under this key
     * @throws OrderNotFound
     * @throws OrderNotPayable
     * @throws InvalidCommand sales.currency_mismatch
     * @throws PaymentExceedsBalance
     * @throws IdempotencyConflict
     * @throws PaymentDeclined the gateway refused: the attempt is `failed`, a new one may follow
     * @throws PaymentOutcomeUnknown the attempt is `unknown`; reconcile it
     * @throws InvalidGatewayResult the gateway's answer breaks its contract: the attempt is `unknown`
     */
    public function initiate(InitiatePayment $command): PaymentView;

    /**
     * Records an event of the provider once per `(gateway, externalEventId)` and moves the payment forward; an event
     * that would move it back is kept as `ignored`. Money captured on a cancelled order is kept, and the order records
     * `sales.payment_requires_refund`.
     *
     * @throws PaymentNotFound nothing is recorded, so the provider's retry can succeed later
     * @throws InvalidPaymentEvent
     * @throws IdempotencyConflict the event id was recorded with another content
     */
    public function recordEvent(RecordPaymentEvent $command): PaymentEventResult;

    /**
     * Asks the gateway the payment was made at what became of it; must be called outside any database transaction. An
     * attempt the provider never saw becomes `failed`; a final payment is returned without calling the gateway.
     *
     * @throws OperationInsideTransaction
     * @throws PaymentNotFound
     * @throws UnsupportedContribution the payment's gateway is no longer installed
     * @throws PaymentInProgress the attempt is being initiated under a live lease
     * @throws PaymentOutcomeUnknown the gateway did not answer
     * @throws InvalidGatewayResult
     */
    public function reconcile(ReconcilePayment $command): PaymentView;

    /** @throws PaymentNotFound */
    public function find(PaymentId $id): PaymentView;

    /** @return Page<PaymentView> */
    public function list(ListPayments $query): Page;
}
