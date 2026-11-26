<?php

declare(strict_types=1);

namespace Commerz\Sales\Payments\Contracts;

use Commerz\Errors\IdempotencyConflict;
use Commerz\Errors\InvalidCommand;
use Commerz\Ports\StoredValue\Contracts\StoredValueTender;
use Commerz\Ports\StoredValue\Exceptions\InsufficientStoredValue;
use Commerz\Ports\StoredValue\Exceptions\StoredValueNotFound;
use Commerz\Ports\StoredValue\Exceptions\StoredValueUnavailable;
use Commerz\Sales\Common\Exceptions\OperationInsideTransaction;
use Commerz\Sales\Gateways\Exceptions\GatewayCapabilityUnsupported;
use Commerz\Sales\Gateways\Exceptions\InvalidGatewayResult;
use Commerz\Sales\Gateways\Exceptions\PaymentDeclined;
use Commerz\Sales\Orders\Data\OrderId;
use Commerz\Sales\Orders\Exceptions\OrderNotFound;
use Commerz\Sales\Payments\Data\AddStoredValueSession;
use Commerz\Sales\Payments\Data\AuthorizeGatewaySession;
use Commerz\Sales\Payments\Data\CancelPaymentCollection;
use Commerz\Sales\Payments\Data\CapturePaymentSession;
use Commerz\Sales\Payments\Data\CreatePaymentCollection;
use Commerz\Sales\Payments\Data\IncrementalAuthorizeSession;
use Commerz\Sales\Payments\Data\PaymentCollectionId;
use Commerz\Sales\Payments\Data\PaymentCollectionView;
use Commerz\Sales\Payments\Data\VoidPaymentSession;
use Commerz\Sales\Payments\Exceptions\CaptureExceedsAuthorization;
use Commerz\Sales\Payments\Exceptions\InvalidPaymentSessionState;
use Commerz\Sales\Payments\Exceptions\OrderNotPayable;
use Commerz\Sales\Payments\Exceptions\PaymentCollectionNotFound;
use Commerz\Sales\Payments\Exceptions\PaymentExceedsBalance;
use Commerz\Sales\Payments\Exceptions\PaymentOutcomeUnknown;
use Commerz\Sales\Payments\Exceptions\PaymentSessionNotFound;
use Packman\Attributes\Api;
use Packman\Plugins\Exceptions\Registry\UnsupportedContribution;

/**
 * Split-tender and flexible-acquiring payment collections (D11, D33, PAY-02).
 *
 * Combines {@see StoredValueTender} sessions and external card/acquiring gateway
 * sessions in one collection for an order or top-up, supporting separate authorize/capture/void, multicapture
 * (`isFinalCapture: false` then `true`), incremental authorization, overcapture, and Level 3 line items.
 *
 * Every external gateway call runs strictly outside any database transaction (D33).
 */
#[Api(since: '0.1')]
interface PaymentCollections
{
    /**
     * Opens a new payment collection for an order or top-up.
     *
     * @throws OrderNotFound
     * @throws OrderNotPayable
     * @throws InvalidCommand
     * @throws IdempotencyConflict
     */
    public function create(CreatePaymentCollection $command): PaymentCollectionView;

    /** @throws PaymentCollectionNotFound */
    public function find(PaymentCollectionId $id): PaymentCollectionView;

    /** @return list<PaymentCollectionView> */
    public function forOrder(OrderId $order): array;

    /** @return list<PaymentCollectionView> */
    public function forTarget(string $targetRef): array;

    /**
     * Places a stored-value hold (and optional immediate capture) as a session inside the collection.
     *
     * @throws PaymentCollectionNotFound
     * @throws InvalidPaymentSessionState
     * @throws InvalidCommand
     * @throws PaymentExceedsBalance
     * @throws IdempotencyConflict
     * @throws StoredValueNotFound
     * @throws StoredValueUnavailable
     * @throws InsufficientStoredValue
     */
    public function addStoredValueSession(AddStoredValueSession $command): PaymentCollectionView;

    /**
     * Authorizes (or auto-captures) a gateway session outside any database transaction (D33).
     *
     * @throws OperationInsideTransaction
     * @throws PaymentCollectionNotFound
     * @throws UnsupportedContribution
     * @throws GatewayCapabilityUnsupported
     * @throws InvalidPaymentSessionState
     * @throws InvalidCommand
     * @throws PaymentExceedsBalance
     * @throws IdempotencyConflict
     * @throws PaymentDeclined
     * @throws PaymentOutcomeUnknown
     * @throws InvalidGatewayResult
     */
    public function authorizeGatewaySession(AuthorizeGatewaySession $command): PaymentCollectionView;

    /**
     * Captures all or part of an authorized session (gateway or stored value).
     *
     * Supports multicapture (`isFinalCapture: false` leaves remaining authorization open; `isFinalCapture: true`
     * closes the authorization). Gateway captures run strictly outside any database transaction (D33).
     *
     * @throws OperationInsideTransaction
     * @throws PaymentSessionNotFound
     * @throws UnsupportedContribution
     * @throws GatewayCapabilityUnsupported
     * @throws InvalidPaymentSessionState
     * @throws InvalidCommand
     * @throws CaptureExceedsAuthorization
     * @throws IdempotencyConflict
     * @throws PaymentDeclined
     * @throws PaymentOutcomeUnknown
     * @throws InvalidGatewayResult
     * @throws StoredValueNotFound
     * @throws StoredValueUnavailable
     * @throws InsufficientStoredValue
     */
    public function captureSession(CapturePaymentSession $command): PaymentCollectionView;

    /**
     * Increases the authorized amount of an open gateway session outside any database transaction (D33).
     *
     * @throws OperationInsideTransaction
     * @throws PaymentSessionNotFound
     * @throws UnsupportedContribution
     * @throws GatewayCapabilityUnsupported
     * @throws InvalidPaymentSessionState
     * @throws InvalidCommand
     * @throws IdempotencyConflict
     * @throws PaymentDeclined
     * @throws PaymentOutcomeUnknown
     * @throws InvalidGatewayResult
     */
    public function incrementalAuthorize(IncrementalAuthorizeSession $command): PaymentCollectionView;

    /**
     * Voids an uncaptured gateway authorization (outside any transaction) or releases a stored-value hold.
     *
     * @throws OperationInsideTransaction
     * @throws PaymentSessionNotFound
     * @throws UnsupportedContribution
     * @throws GatewayCapabilityUnsupported
     * @throws InvalidPaymentSessionState
     * @throws IdempotencyConflict
     * @throws PaymentDeclined
     * @throws PaymentOutcomeUnknown
     * @throws InvalidGatewayResult
     * @throws StoredValueNotFound
     * @throws StoredValueUnavailable
     */
    public function voidSession(VoidPaymentSession $command): PaymentCollectionView;

    /**
     * Cancels a collection with zero captured funds by voiding/releasing all open sessions.
     *
     * @throws OperationInsideTransaction
     * @throws PaymentCollectionNotFound
     * @throws InvalidPaymentSessionState
     * @throws UnsupportedContribution
     * @throws GatewayCapabilityUnsupported
     * @throws PaymentDeclined
     * @throws PaymentOutcomeUnknown
     * @throws InvalidGatewayResult
     * @throws StoredValueNotFound
     * @throws StoredValueUnavailable
     */
    public function cancel(CancelPaymentCollection $command): PaymentCollectionView;
}
