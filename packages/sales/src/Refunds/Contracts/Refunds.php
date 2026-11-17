<?php

declare(strict_types=1);

namespace Commerz\Sales\Refunds\Contracts;

use Commerz\Errors\IdempotencyConflict;
use Commerz\Errors\InvalidCommand;
use Commerz\Pagination\Page;
use Commerz\Sales\Common\Exceptions\OperationInsideTransaction;
use Commerz\Sales\Gateways\Exceptions\InvalidGatewayResult;
use Commerz\Sales\Gateways\Exceptions\PaymentDeclined;
use Commerz\Sales\Orders\Exceptions\OrderNotFound;
use Commerz\Sales\Payments\Exceptions\InvalidPaymentEvent;
use Commerz\Sales\Payments\Exceptions\PaymentNotFound;
use Commerz\Sales\Refunds\Data\IssueRefund;
use Commerz\Sales\Refunds\Data\ListRefunds;
use Commerz\Sales\Refunds\Data\ReconcileRefund;
use Commerz\Sales\Refunds\Data\RecordRefundEvent;
use Commerz\Sales\Refunds\Data\RefundEventResult;
use Commerz\Sales\Refunds\Data\RefundId;
use Commerz\Sales\Refunds\Data\RefundView;
use Commerz\Sales\Refunds\Exceptions\RefundExceedsBalance;
use Commerz\Sales\Refunds\Exceptions\RefundInProgress;
use Commerz\Sales\Refunds\Exceptions\RefundNotFound;
use Commerz\Sales\Refunds\Exceptions\RefundOutcomeUnknown;
use Commerz\Sales\Returns\Exceptions\ReturnNotFound;
use Packman\Attributes\Api;
use Packman\Plugins\Exceptions\Registry\UnsupportedContribution;

/**
 * Money given back through the gateway of a captured payment (REF-01, D33): the same journal as payments — the attempt
 * is written before the gateway is called, the gateway is called outside any database transaction, and an answer that
 * is not certain leaves the refund `unknown` until `reconcile`. Never more than a payment captured goes back, and a
 * refund never touches the stock: goods come back only through `Returns::receive`.
 */
#[Api(since: '0.1')]
interface Refunds
{
    /**
     * Refunds an amount of an order through the gateway of one payment; must be called outside any database
     * transaction. A repeat with the same idempotency key returns the refund as it is now, without calling the gateway
     * again. A refund that leaves the order fully refunded gives its promotion usage back (`refunded`, as the promotions'
     * `restore_on` policy allows).
     *
     * @throws OperationInsideTransaction
     * @throws OrderNotFound
     * @throws PaymentNotFound the payment is not one of the order
     * @throws ReturnNotFound the return is not one of the order
     * @throws InvalidCommand sales.currency_mismatch
     * @throws RefundExceedsBalance
     * @throws UnsupportedContribution the payment's gateway is no longer installed
     * @throws IdempotencyConflict
     * @throws PaymentDeclined the gateway refused: the refund is `failed`, its amount may be refunded anew
     * @throws RefundOutcomeUnknown the refund is `unknown`; reconcile it
     * @throws InvalidGatewayResult the gateway's answer breaks its contract: the refund is `unknown`
     */
    public function issue(IssueRefund $command): RefundView;

    /**
     * Records an event of the provider about a refund once per `(gateway, externalEventId)`. A succeeded refund keeps
     * its status and the event is kept as `ignored`; a failed one still takes a success, unless its amount was
     * refunded again meanwhile (the order journals `refund_over_refunded`).
     *
     * @throws RefundNotFound nothing is recorded, so the provider's retry can succeed later
     * @throws InvalidPaymentEvent
     * @throws IdempotencyConflict the event id was recorded with another content
     */
    public function recordEvent(RecordRefundEvent $command): RefundEventResult;

    /**
     * Asks the gateway the refund was made at what became of it; must be called outside any database transaction. A
     * refund the provider never saw becomes `failed`; a final refund is returned without calling the gateway.
     *
     * @throws OperationInsideTransaction
     * @throws RefundNotFound
     * @throws UnsupportedContribution the refund's gateway is no longer installed
     * @throws RefundInProgress the refund is being issued under a live lease
     * @throws RefundOutcomeUnknown the gateway did not answer
     * @throws InvalidGatewayResult
     */
    public function reconcile(ReconcileRefund $command): RefundView;

    /** @throws RefundNotFound */
    public function find(RefundId $id): RefundView;

    /** @return Page<RefundView> */
    public function list(ListRefunds $query): Page;
}
