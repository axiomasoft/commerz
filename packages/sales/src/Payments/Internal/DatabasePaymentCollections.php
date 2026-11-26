<?php

declare(strict_types=1);

namespace Commerz\Sales\Payments\Internal;

use Commerz\Errors\InvalidCommand;
use Commerz\Money\Money;
use Commerz\Operations\Contracts\OperationReceipts;
use Commerz\Operations\Contracts\Transactions;
use Commerz\Operations\Fingerprint;
use Commerz\Ports\StoredValue\Contracts\StoredValueTender;
use Commerz\Ports\StoredValue\Data\CaptureStoredValue;
use Commerz\Ports\StoredValue\Data\HoldStoredValue;
use Commerz\Ports\StoredValue\Data\ReleaseStoredValue;
use Commerz\Sales\Gateways\Data\GatewayAuthorizeRequest;
use Commerz\Sales\Gateways\Data\GatewayCaptureRequest;
use Commerz\Sales\Gateways\Data\GatewayCaptureResult;
use Commerz\Sales\Gateways\Data\GatewayIncrementalAuthRequest;
use Commerz\Sales\Gateways\Data\GatewayPaymentResult;
use Commerz\Sales\Gateways\Data\GatewayVoidRequest;
use Commerz\Sales\Gateways\Enums\GatewayPaymentStatus;
use Commerz\Sales\Gateways\Exceptions\InvalidGatewayResult;
use Commerz\Sales\Gateways\Exceptions\PaymentDeclined;
use Commerz\Sales\Orders\Data\OrderId;
use Commerz\Sales\Payments\Contracts\PaymentCollections;
use Commerz\Sales\Payments\Data\AddStoredValueSession;
use Commerz\Sales\Payments\Data\AuthorizeGatewaySession;
use Commerz\Sales\Payments\Data\CancelPaymentCollection;
use Commerz\Sales\Payments\Data\CapturePaymentSession;
use Commerz\Sales\Payments\Data\CreatePaymentCollection;
use Commerz\Sales\Payments\Data\IncrementalAuthorizeSession;
use Commerz\Sales\Payments\Data\PaymentCaptureId;
use Commerz\Sales\Payments\Data\PaymentCollectionId;
use Commerz\Sales\Payments\Data\PaymentCollectionView;
use Commerz\Sales\Payments\Data\PaymentSessionId;
use Commerz\Sales\Payments\Data\VoidPaymentSession;
use Commerz\Sales\Payments\Enums\PaymentCaptureStatus;
use Commerz\Sales\Payments\Enums\PaymentCollectionStatus;
use Commerz\Sales\Payments\Enums\PaymentSessionKind;
use Commerz\Sales\Payments\Enums\PaymentSessionStatus;
use Commerz\Sales\Payments\Exceptions\CaptureExceedsAuthorization;
use Commerz\Sales\Payments\Exceptions\InvalidPaymentSessionState;
use Commerz\Sales\Payments\Exceptions\PaymentOutcomeUnknown;
use JsonException;
use LogicException;
use Override;
use Packman\Attributes\Internal;
use Throwable;

/**
 * Coordinates split-tender payment collections combining {@see StoredValueTender} and external acquiring gateways.
 *
 * Every external gateway call is delegated to {@see PaymentWorkflow} strictly outside any database transaction (D33).
 */
#[Internal]
final readonly class DatabasePaymentCollections implements PaymentCollections
{
    private const string SCOPE_CREATE = 'sales.create_payment_collection';

    private const string SCOPE_STORED_VALUE = 'sales.add_stored_value_session';

    private const string SCOPE_AUTHORIZE = 'sales.authorize_gateway_session';

    private const string SCOPE_CAPTURE = 'sales.capture_payment_session';

    private const string SCOPE_INCREMENTAL = 'sales.incremental_authorize_session';

    private const string SCOPE_VOID = 'sales.void_payment_session';

    public function __construct(
        private Transactions $transactions,
        private OperationReceipts $receipts,
        private StoredValueTender $storedValue,
        private PaymentWorkflow $workflow,
        private PaymentCollectionJournal $journal,
    ) {}

    #[Override]
    public function create(CreatePaymentCollection $command): PaymentCollectionView
    {
        $row = $this->transactions->run(function () use ($command): PaymentCollectionRow {
            if ($command->idempotencyKey === null) {
                return $this->journal->create($command);
            }

            $fingerprint = Fingerprint::of([
                'kind' => $command->kind->value,
                'target_ref' => $command->targetRef,
                'order' => $command->order?->value,
                'amount' => [$command->amount->amountMinor, $command->amount->currency->code],
                'meta' => $command->meta,
            ]);

            return $this->receipts->once(
                self::SCOPE_CREATE,
                $command->idempotencyKey,
                $fingerprint,
                fn (): PaymentCollectionRow => $this->journal->create($command),
                static fn (PaymentCollectionRow $row): array => ['collection_id' => $row->id->value],
                fn (array $stored): PaymentCollectionRow => $this->journal->get(new PaymentCollectionId(
                    is_string($stored['collection_id'] ?? null) ? $stored['collection_id'] : throw new LogicException('Payment collection receipt missing collection_id.'),
                )),
            );
        });

        return $this->journal->view($row->id);
    }

    #[Override]
    public function find(PaymentCollectionId $id): PaymentCollectionView
    {
        return $this->journal->view($id);
    }

    /** @return list<PaymentCollectionView> */
    #[Override]
    public function forOrder(OrderId $order): array
    {
        return $this->journal->forOrder($order);
    }

    /** @return list<PaymentCollectionView> */
    #[Override]
    public function forTarget(string $targetRef): array
    {
        return $this->journal->forTarget($targetRef);
    }

    #[Override]
    public function addStoredValueSession(AddStoredValueSession $command): PaymentCollectionView
    {
        $fingerprint = Fingerprint::of([
            'collection' => $command->collection->value,
            'kind' => $command->storedValueKind->value,
            'account' => $command->accountId,
            'amount' => [$command->amount->amountMinor, $command->amount->currency->code],
            'capture_immediately' => $command->captureImmediately,
            'meta' => $command->meta,
        ]);

        $this->transactions->run(function () use ($command, $fingerprint): PaymentSessionRow {
            $open = function () use ($command, $fingerprint): PaymentSessionRow {
                $collection = $this->journal->lock($command->collection);
                $this->journal->resolveSessionAmount($collection, $command->amount);

                $baseKey = $command->idempotencyKey !== null
                    ? 'sv:'.$command->collection->value.':'.$command->idempotencyKey->value
                    : 'sv:'.$command->collection->value.':'.substr($fingerprint, 0, 24).':'.count($this->journal->sessions($collection->id));

                $hold = $this->storedValue->hold(new HoldStoredValue(
                    accountId: $command->accountId,
                    orderRef: $collection->targetRef,
                    amount: $command->amount,
                    idempotencyKey: $baseKey.':hold',
                    expiresAt: $command->expiresAt,
                    actorRef: $command->actor !== null ? (string) $command->actor : null,
                ));

                $capture = null;

                if ($command->captureImmediately) {
                    $capture = $this->storedValue->capture(new CaptureStoredValue(
                        holdId: $hold->holdId,
                        reference: $collection->targetRef,
                        idempotencyKey: $baseKey.':capture',
                        amount: $command->amount,
                        actorRef: $command->actor !== null ? (string) $command->actor : null,
                    ));
                }

                return $this->journal->recordStoredValueSession($collection, $command, $hold, $capture, $fingerprint);
            };

            if ($command->idempotencyKey === null) {
                return $open();
            }

            return $this->receipts->once(
                self::SCOPE_STORED_VALUE,
                $command->idempotencyKey,
                $fingerprint,
                $open,
                static fn (PaymentSessionRow $session): array => ['session_id' => $session->id->value],
                fn (array $stored): PaymentSessionRow => $this->journal->getSession(new PaymentSessionId(
                    is_string($stored['session_id'] ?? null) ? $stored['session_id'] : throw new LogicException('Stored-value session receipt missing session_id.'),
                )),
            );
        });

        return $this->journal->view($command->collection);
    }

    #[Override]
    public function authorizeGatewaySession(AuthorizeGatewaySession $command): PaymentCollectionView
    {
        $this->workflow->assertNotInTransaction('sales.payment_collections.authorize');
        $this->workflow->validateCapabilityForAuthorize($command->gateway, $command->autoCapture, $command->lineItems !== []);

        $request = null;
        $fingerprint = Fingerprint::of([
            'collection' => $command->collection->value,
            'gateway' => $command->gateway,
            'amount' => $command->amount === null ? null : [$command->amount->amountMinor, $command->amount->currency->code],
            'auto_capture' => $command->autoCapture,
            'line_items_count' => count($command->lineItems),
            'meta' => $command->meta,
        ]);

        // Tx A: reserve session attempt under collection lock.
        $session = $this->transactions->run(function () use ($command, $fingerprint, &$request): PaymentSessionRow {
            $open = function () use ($command, $fingerprint, &$request): PaymentSessionRow {
                $collection = $this->journal->lock($command->collection);
                $session = $this->journal->openGatewaySession($collection, $command, $fingerprint);
                $request = new GatewayAuthorizeRequest(
                    reference: $session->reference,
                    targetRef: $collection->targetRef,
                    amount: Money::ofMinor($session->amount, $session->currency),
                    orderNumber: $this->journal->orderNumber($collection),
                    customer: $collection->customer,
                    customerEmail: $collection->customerEmail,
                    lineItems: $command->lineItems,
                    meta: $command->meta,
                );

                return $session;
            };

            if ($command->idempotencyKey === null) {
                return $open();
            }

            return $this->receipts->once(
                self::SCOPE_AUTHORIZE,
                $command->idempotencyKey,
                $fingerprint,
                $open,
                static fn (PaymentSessionRow $session): array => ['session_id' => $session->id->value],
                fn (array $stored): PaymentSessionRow => $this->journal->getSession(new PaymentSessionId(
                    is_string($stored['session_id'] ?? null) ? $stored['session_id'] : throw new LogicException('Gateway session receipt missing session_id.'),
                )),
            );
        });

        if (! $request instanceof GatewayAuthorizeRequest) {
            return $this->journal->view($session->collection);
        }

        // Network call strictly outside any database transaction (D33).
        try {
            $result = $this->workflow->authorizeAtGateway($command->gateway, $request, $command->autoCapture);
        } catch (PaymentDeclined $e) {
            $this->journal->finishSession($session, 1, fn (PaymentCollectionRow $collection, PaymentSessionRow $current): PaymentSessionRow => $this->journal->updateSession(
                $current->id,
                ['status' => PaymentSessionStatus::Failed->value, 'capture_closed' => true] + PaymentJournal::error($e),
            ));

            throw $e;
        } catch (Throwable $e) {
            $this->markSessionUnknown($session, 1, $e);

            throw new PaymentOutcomeUnknown($session->id->value, $command->gateway, $e);
        }

        try {
            $this->validateGatewayPaymentResult($session, $command->gateway, $result);
        } catch (InvalidGatewayResult $e) {
            $this->markSessionUnknown($session, 1, $e);

            throw $e;
        }

        // Tx B: fenced finish.
        $this->journal->finishSession($session, 1, function (PaymentCollectionRow $collection, PaymentSessionRow $current) use ($result): PaymentSessionRow {
            $columns = [];

            if ($result->externalId !== null && $current->externalId === null) {
                $columns['external_id'] = $result->externalId;
            }

            if ($result->meta !== []) {
                $columns['meta'] = PaymentJournal::json($result->meta + $current->meta);
            }

            if ($result->status === GatewayPaymentStatus::Captured) {
                $capturedMinor = $result->captured !== null ? $result->captured->amountMinor : $current->amount;
                $authorizedFromResult = $result->authorized !== null ? $result->authorized->amountMinor : $capturedMinor;
                $authorizedMinor = max($current->authorized, $authorizedFromResult, $capturedMinor);
                $updated = $this->journal->updateSession($current->id, $columns + [
                    'status' => PaymentSessionStatus::Captured->value,
                    'authorized_minor' => $authorizedMinor,
                    'captured_minor' => $capturedMinor,
                    'capture_closed' => true,
                    'action_url' => $result->actionUrl,
                ]);
                $this->journal->insertAutoCaptureRow($collection, $updated, $capturedMinor, $result->externalId, $result->meta);

                return $updated;
            }

            if ($result->status === GatewayPaymentStatus::Authorized) {
                $authorizedMinor = $result->authorized !== null ? $result->authorized->amountMinor : $current->amount;

                return $this->journal->updateSession($current->id, $columns + [
                    'status' => PaymentSessionStatus::Authorized->value,
                    'authorized_minor' => $authorizedMinor,
                    'action_url' => $result->actionUrl,
                ]);
            }

            $mapped = match ($result->status) {
                GatewayPaymentStatus::Pending => PaymentSessionStatus::Pending,
                GatewayPaymentStatus::RequiresAction => PaymentSessionStatus::RequiresAction,
                GatewayPaymentStatus::Failed => PaymentSessionStatus::Failed,
                GatewayPaymentStatus::Cancelled => PaymentSessionStatus::Voided,
            };

            return $this->journal->updateSession($current->id, $columns + [
                'status' => $mapped->value,
                'capture_closed' => $mapped->isFinal(),
                'action_url' => $result->actionUrl,
            ]);
        });

        return $this->journal->view($session->collection);
    }

    #[Override]
    public function captureSession(CapturePaymentSession $command): PaymentCollectionView
    {
        $located = $this->journal->getSession($command->session);

        if ($located->kind === PaymentSessionKind::StoredValue) {
            return $this->captureStoredValueSession($located, $command);
        }

        return $this->captureGatewaySession($located, $command);
    }

    #[Override]
    public function incrementalAuthorize(IncrementalAuthorizeSession $command): PaymentCollectionView
    {
        $this->workflow->assertNotInTransaction('sales.payment_collections.incremental_authorize');
        $located = $this->journal->getSession($command->session);

        if ($located->kind !== PaymentSessionKind::Gateway || $located->gateway === null) {
            throw new InvalidPaymentSessionState($located->id->value, $located->status->value, 'incremental authorization is only supported for gateway sessions');
        }

        $gatewayKey = $located->gateway;
        $this->workflow->validateCapabilityForIncrementalAuth($gatewayKey);

        $fingerprint = Fingerprint::of([
            'session' => $command->session->value,
            'additional_amount' => [$command->additionalAmount->amountMinor, $command->additionalAmount->currency->code],
            'meta' => $command->meta,
        ]);

        $request = null;

        $claimed = $this->transactions->run(function () use ($command, $located, $fingerprint, &$request): PaymentSessionRow {
            $open = function () use ($command, $located, &$request): PaymentSessionRow {
                $this->journal->lock($located->collection);
                $session = $this->journal->lockSession($located->id);

                if (! PaymentCollectionStateMachine::canIncrementalAuthorize($session)) {
                    throw new InvalidPaymentSessionState($session->id->value, $session->status->value, 'session is not open for incremental authorization');
                }

                if (! $command->additionalAmount->currency->equals($session->currency)) {
                    throw new InvalidCommand('additionalAmount', "must be in {$session->currency->code}, the currency of the session", 'sales.currency_mismatch');
                }

                $leased = $this->journal->leaseSession($session);
                $newTotal = Money::ofMinor($session->authorized + $command->additionalAmount->amountMinor, $session->currency);
                $request = new GatewayIncrementalAuthRequest(
                    reference: 'incauth:'.$leased->id->value.':'.$leased->attempts,
                    paymentReference: $leased->reference,
                    paymentExternalId: $leased->externalId,
                    additionalAmount: $command->additionalAmount,
                    newAuthorizedTotal: $newTotal,
                    meta: $command->meta,
                );

                return $leased;
            };

            if ($command->idempotencyKey === null) {
                return $open();
            }

            return $this->receipts->once(
                self::SCOPE_INCREMENTAL,
                $command->idempotencyKey,
                $fingerprint,
                $open,
                static fn (PaymentSessionRow $session): array => ['session_id' => $session->id->value],
                fn (array $stored): PaymentSessionRow => $this->journal->getSession(new PaymentSessionId(
                    is_string($stored['session_id'] ?? null) ? $stored['session_id'] : throw new LogicException('Incremental auth receipt missing session_id.'),
                )),
            );
        });

        if (! $request instanceof GatewayIncrementalAuthRequest) {
            return $this->journal->view($claimed->collection);
        }

        $attempt = $claimed->attempts;

        try {
            $result = $this->workflow->incrementalAuthorizeAtGateway($gatewayKey, $request);
        } catch (PaymentDeclined $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new PaymentOutcomeUnknown($claimed->id->value, $gatewayKey, $e);
        }

        if ($result->status !== GatewayPaymentStatus::Authorized) {
            throw new InvalidGatewayResult($gatewayKey, "incremental authorization must return status 'authorized', got '{$result->status->value}'");
        }

        $newAuthorizedMinor = $result->authorized !== null
            ? $result->authorized->amountMinor
            : $request->newAuthorizedTotal->amountMinor;

        if ($result->authorized !== null && ! $result->authorized->currency->equals($claimed->currency)) {
            throw new InvalidGatewayResult($gatewayKey, 'incremental authorization returned mismatched currency');
        }

        if ($newAuthorizedMinor < $claimed->authorized) {
            throw new InvalidGatewayResult($gatewayKey, 'incremental authorization decreased authorized amount');
        }

        $this->journal->finishSession($claimed, $attempt, function (PaymentCollectionRow $collection, PaymentSessionRow $current) use ($result, $newAuthorizedMinor): PaymentSessionRow {
            $columns = [
                'authorized_minor' => max($current->authorized, $newAuthorizedMinor),
                'amount_minor' => max($current->amount, $newAuthorizedMinor),
            ];

            if ($result->meta !== []) {
                $columns['meta'] = PaymentJournal::json($result->meta + $current->meta);
            }

            return $this->journal->updateSession($current->id, $columns);
        });

        return $this->journal->view($claimed->collection);
    }

    #[Override]
    public function voidSession(VoidPaymentSession $command): PaymentCollectionView
    {
        $located = $this->journal->getSession($command->session);

        if ($located->kind === PaymentSessionKind::StoredValue) {
            return $this->voidStoredValueSession($located, $command);
        }

        return $this->voidGatewaySession($located, $command);
    }

    #[Override]
    public function cancel(CancelPaymentCollection $command): PaymentCollectionView
    {
        $this->workflow->assertNotInTransaction('sales.payment_collections.cancel');
        $view = $this->journal->view($command->collection);

        if ($view->status === PaymentCollectionStatus::Cancelled) {
            return $view;
        }

        if ($view->captured->isPositive()) {
            throw new InvalidPaymentSessionState($view->id->value, $view->status->value, 'cannot cancel a collection with captured funds');
        }

        foreach ($view->sessions as $session) {
            if ($session->captured->isZero() && in_array($session->status, [PaymentSessionStatus::Pending, PaymentSessionStatus::RequiresAction, PaymentSessionStatus::Authorized], true)) {
                $this->voidSession(new VoidPaymentSession($session->id, $command->reason, actor: $command->actor));
            }
        }

        $this->transactions->run(function () use ($command): void {
            $collection = $this->journal->lock($command->collection);

            if ($collection->captured > 0) {
                throw new InvalidPaymentSessionState($collection->id->value, $collection->status->value, 'cannot cancel a collection with captured funds');
            }

            $this->journal->refreshCollection($collection, cancelled: true);
        });

        return $this->journal->view($command->collection);
    }

    private function captureStoredValueSession(PaymentSessionRow $located, CapturePaymentSession $command): PaymentCollectionView
    {
        $fingerprint = Fingerprint::of([
            'session' => $command->session->value,
            'amount' => $command->amount === null ? null : [$command->amount->amountMinor, $command->amount->currency->code],
            'is_final_capture' => $command->isFinalCapture,
            'meta' => $command->meta,
        ]);

        $this->transactions->run(function () use ($located, $command, $fingerprint): PaymentSessionRow {
            $run = function () use ($located, $command, $fingerprint): PaymentSessionRow {
                $collection = $this->journal->lock($located->collection);
                $session = $this->journal->lockSession($located->id);

                if (! PaymentCollectionStateMachine::canCapture($session) || $session->storedValueHoldId === null) {
                    throw new InvalidPaymentSessionState($session->id->value, $session->status->value, 'stored-value session is not open for capture');
                }

                if ($command->amount !== null && ! $command->amount->currency->equals($session->currency)) {
                    throw new InvalidCommand('amount', "must be in {$session->currency->code}, the currency of the session", 'sales.currency_mismatch');
                }

                $remainingMinor = max(0, $session->authorized - $session->captured);
                $remaining = Money::ofMinor($remainingMinor, $session->currency);
                $amount = $command->amount ?? $remaining;

                if (! $amount->isPositive() || $amount->amountMinor > $remainingMinor) {
                    throw new CaptureExceedsAuthorization($session->id->value, $amount, $remaining);
                }

                $captureCount = $this->journal->succeededCaptureCount($session->id);
                $idemBase = $command->idempotencyKey !== null
                    ? 'svcap:'.$session->id->value.':'.$command->idempotencyKey->value
                    : 'svcap:'.$session->id->value.':'.$captureCount.':'.$amount->amountMinor;

                $captured = $this->storedValue->capture(new CaptureStoredValue(
                    holdId: $session->storedValueHoldId,
                    reference: $collection->targetRef,
                    idempotencyKey: $idemBase,
                    amount: $amount,
                    actorRef: $command->actor !== null ? (string) $command->actor : null,
                ));

                $leftoverMinor = $remainingMinor - $amount->amountMinor;

                if ($command->isFinalCapture && $leftoverMinor > 0) {
                    $this->storedValue->release(new ReleaseStoredValue(
                        holdId: $session->storedValueHoldId,
                        idempotencyKey: $idemBase.':release_remainder',
                        amount: Money::ofMinor($leftoverMinor, $session->currency),
                        actorRef: $command->actor !== null ? (string) $command->actor : null,
                        reason: 'final_capture_release',
                    ));
                }

                return $this->journal->recordStoredValueCapture($collection, $session, $captured, $command->isFinalCapture, $command, $fingerprint);
            };

            if ($command->idempotencyKey === null) {
                return $run();
            }

            return $this->receipts->once(
                self::SCOPE_CAPTURE,
                $command->idempotencyKey,
                $fingerprint,
                $run,
                static fn (PaymentSessionRow $session): array => ['session_id' => $session->id->value],
                fn (array $stored): PaymentSessionRow => $this->journal->getSession(new PaymentSessionId(
                    is_string($stored['session_id'] ?? null) ? $stored['session_id'] : throw new LogicException('Capture receipt missing session_id.'),
                )),
            );
        });

        return $this->journal->view($located->collection);
    }

    private function captureGatewaySession(PaymentSessionRow $located, CapturePaymentSession $command): PaymentCollectionView
    {
        $this->workflow->assertNotInTransaction('sales.payment_collections.capture');

        if ($located->gateway === null) {
            throw new InvalidPaymentSessionState($located->id->value, $located->status->value, 'gateway session has no gateway key');
        }

        $gatewayKey = $located->gateway;
        $fingerprint = Fingerprint::of([
            'session' => $command->session->value,
            'amount' => $command->amount === null ? null : [$command->amount->amountMinor, $command->amount->currency->code],
            'is_final_capture' => $command->isFinalCapture,
            'line_items_count' => count($command->lineItems),
            'meta' => $command->meta,
        ]);

        $request = null;
        $isSubsequentCapture = false;
        $isOvercapture = false;

        // Tx A: validate state, capabilities, and write capture intent row.
        $captureRow = $this->transactions->run(function () use ($located, $command, $gatewayKey, $fingerprint, &$request, &$isSubsequentCapture, &$isOvercapture): PaymentCaptureRow {
            $open = function () use ($located, $command, $gatewayKey, $fingerprint, &$request, &$isSubsequentCapture, &$isOvercapture): PaymentCaptureRow {
                $this->journal->lock($located->collection);
                $session = $this->journal->lockSession($located->id);

                if (! PaymentCollectionStateMachine::canCapture($session)) {
                    throw new InvalidPaymentSessionState($session->id->value, $session->status->value, 'session is not open for capture');
                }

                if ($command->amount !== null && ! $command->amount->currency->equals($session->currency)) {
                    throw new InvalidCommand('amount', "must be in {$session->currency->code}, the currency of the session", 'sales.currency_mismatch');
                }

                $isSubsequentCapture = $this->journal->succeededCaptureCount($session->id) > 0;
                $capabilities = $this->workflow->validateCapabilityForCapture(
                    $gatewayKey,
                    $command->isFinalCapture,
                    $isSubsequentCapture,
                    $command->lineItems !== [],
                );

                $remainingMinor = max(0, $session->authorized - $session->captured);
                $remaining = Money::ofMinor($remainingMinor, $session->currency);
                $amount = $command->amount ?? $remaining;

                if (! $amount->isPositive()) {
                    throw new CaptureExceedsAuthorization($session->id->value, $amount, $remaining);
                }

                $isOvercapture = ($session->captured + $amount->amountMinor) > $session->authorized;

                if ($isOvercapture && ! $capabilities->overcapture) {
                    throw new CaptureExceedsAuthorization($session->id->value, $amount, $remaining);
                }

                $capture = $this->journal->openCapture($session, $amount, $command->isFinalCapture, $command, $fingerprint);
                $request = new GatewayCaptureRequest(
                    reference: $capture->reference,
                    paymentReference: $session->reference,
                    paymentExternalId: $session->externalId,
                    amount: $amount,
                    isFinalCapture: $command->isFinalCapture,
                    lineItems: $command->lineItems,
                    meta: $command->meta,
                );

                return $capture;
            };

            if ($command->idempotencyKey === null) {
                return $open();
            }

            return $this->receipts->once(
                self::SCOPE_CAPTURE,
                $command->idempotencyKey,
                $fingerprint,
                $open,
                static fn (PaymentCaptureRow $capture): array => ['capture_id' => $capture->id->value],
                fn (array $stored): PaymentCaptureRow => $this->journal->getCapture(new PaymentCaptureId(
                    is_string($stored['capture_id'] ?? null) ? $stored['capture_id'] : throw new LogicException('Capture receipt missing capture_id.'),
                )),
            );
        });

        if (! $request instanceof GatewayCaptureRequest) {
            return $this->journal->view($located->collection);
        }

        // Network call strictly outside any database transaction (D33).
        try {
            $result = $this->workflow->captureAtGateway($gatewayKey, $request, $isSubsequentCapture, $isOvercapture);
        } catch (PaymentDeclined $e) {
            $this->journal->finishCapture($captureRow, 1, function (PaymentCollectionRow $collection, PaymentSessionRow $session, PaymentCaptureRow $current) use ($e): void {
                $this->journal->updateCapture($current->id, ['status' => PaymentCaptureStatus::Failed->value] + PaymentJournal::error($e));
            });

            throw $e;
        } catch (Throwable $e) {
            $this->journal->finishCapture($captureRow, 1, function (PaymentCollectionRow $collection, PaymentSessionRow $session, PaymentCaptureRow $current) use ($e): void {
                $this->journal->updateCapture($current->id, ['status' => PaymentCaptureStatus::Unknown->value] + PaymentJournal::error($e));
            });

            throw new PaymentOutcomeUnknown($captureRow->id->value, $gatewayKey, $e);
        }

        try {
            $this->validateGatewayCaptureResult($captureRow, $gatewayKey, $result);
        } catch (InvalidGatewayResult $e) {
            $this->journal->finishCapture($captureRow, 1, function (PaymentCollectionRow $collection, PaymentSessionRow $session, PaymentCaptureRow $current) use ($e): void {
                $this->journal->updateCapture($current->id, ['status' => PaymentCaptureStatus::Unknown->value] + PaymentJournal::error($e));
            });

            throw $e;
        }

        // Tx B: record capture outcome and update session + collection.
        $this->journal->finishCapture($captureRow, 1, function (PaymentCollectionRow $collection, PaymentSessionRow $session, PaymentCaptureRow $current) use ($result, $command): void {
            $captureColumns = [];

            if ($result->externalId !== null) {
                $captureColumns['external_id'] = $result->externalId;
            }

            if ($result->meta !== []) {
                $captureColumns['meta'] = PaymentJournal::json($result->meta + $current->meta);
            }

            if ($result->status === GatewayPaymentStatus::Captured) {
                $closed = $command->isFinalCapture || $result->isFinalCapture;
                $capturedMinor = $result->captured->amountMinor;
                $this->journal->updateCapture($current->id, $captureColumns + [
                    'status' => PaymentCaptureStatus::Succeeded->value,
                    'amount_minor' => $capturedMinor,
                    'is_final_capture' => $closed,
                ]);

                $newCaptured = $session->captured + $capturedMinor;
                $nextSessionStatus = $closed ? PaymentSessionStatus::Captured : PaymentSessionStatus::PartiallyCaptured;

                $this->journal->updateSession($session->id, [
                    'captured_minor' => $newCaptured,
                    'capture_closed' => $closed,
                    'status' => $nextSessionStatus->value,
                ]);

                return;
            }

            $mapped = match ($result->status) {
                GatewayPaymentStatus::Pending => PaymentCaptureStatus::Pending,
                default => PaymentCaptureStatus::Failed,
            };

            $this->journal->updateCapture($current->id, $captureColumns + ['status' => $mapped->value]);
        });

        return $this->journal->view($located->collection);
    }

    private function voidStoredValueSession(PaymentSessionRow $located, VoidPaymentSession $command): PaymentCollectionView
    {
        $fingerprint = Fingerprint::of([
            'session' => $command->session->value,
            'reason' => $command->reason,
        ]);

        $this->transactions->run(function () use ($located, $command, $fingerprint): PaymentSessionRow {
            $run = function () use ($located, $command): PaymentSessionRow {
                $collection = $this->journal->lock($located->collection);
                $session = $this->journal->lockSession($located->id);

                if ($session->status === PaymentSessionStatus::Voided) {
                    return $session;
                }

                if (! PaymentCollectionStateMachine::canVoid($session)) {
                    throw new InvalidPaymentSessionState($session->id->value, $session->status->value, 'stored-value session cannot be voided');
                }

                if ($session->storedValueHoldId !== null) {
                    $this->storedValue->release(new ReleaseStoredValue(
                        holdId: $session->storedValueHoldId,
                        idempotencyKey: 'svvoid:'.$session->id->value,
                        actorRef: $command->actor !== null ? (string) $command->actor : null,
                        reason: $command->reason,
                    ));
                }

                $updated = $this->journal->updateSession($session->id, [
                    'status' => PaymentSessionStatus::Voided->value,
                    'capture_closed' => true,
                ]);
                $this->journal->refreshCollection($collection);

                return $updated;
            };

            if ($command->idempotencyKey === null) {
                return $run();
            }

            return $this->receipts->once(
                self::SCOPE_VOID,
                $command->idempotencyKey,
                $fingerprint,
                $run,
                static fn (PaymentSessionRow $session): array => ['session_id' => $session->id->value],
                fn (array $stored): PaymentSessionRow => $this->journal->getSession(new PaymentSessionId(
                    is_string($stored['session_id'] ?? null) ? $stored['session_id'] : throw new LogicException('Void receipt missing session_id.'),
                )),
            );
        });

        return $this->journal->view($located->collection);
    }

    private function voidGatewaySession(PaymentSessionRow $located, VoidPaymentSession $command): PaymentCollectionView
    {
        $this->workflow->assertNotInTransaction('sales.payment_collections.void');

        if ($located->gateway === null) {
            throw new InvalidPaymentSessionState($located->id->value, $located->status->value, 'gateway session has no gateway key');
        }

        $gatewayKey = $located->gateway;
        $fingerprint = Fingerprint::of([
            'session' => $command->session->value,
            'reason' => $command->reason,
        ]);

        $request = null;

        $claimed = $this->transactions->run(function () use ($located, $command, $gatewayKey, $fingerprint, &$request): PaymentSessionRow {
            $open = function () use ($located, $command, $gatewayKey, &$request): PaymentSessionRow {
                $collection = $this->journal->lock($located->collection);
                $session = $this->journal->lockSession($located->id);

                if ($session->status === PaymentSessionStatus::Voided) {
                    return $session;
                }

                if (! PaymentCollectionStateMachine::canVoid($session)) {
                    throw new InvalidPaymentSessionState($session->id->value, $session->status->value, 'session cannot be voided');
                }

                if ($session->status !== PaymentSessionStatus::Authorized) {
                    $updated = $this->journal->updateSession($session->id, [
                        'status' => PaymentSessionStatus::Voided->value,
                        'capture_closed' => true,
                    ]);
                    $this->journal->refreshCollection($collection);

                    return $updated;
                }

                $this->workflow->validateCapabilityForVoid($gatewayKey);
                $leased = $this->journal->leaseSession($session);
                $request = new GatewayVoidRequest(
                    reference: 'void:'.$leased->id->value.':'.$leased->attempts,
                    paymentReference: $leased->reference,
                    paymentExternalId: $leased->externalId,
                    reason: $command->reason,
                );

                return $leased;
            };

            if ($command->idempotencyKey === null) {
                return $open();
            }

            return $this->receipts->once(
                self::SCOPE_VOID,
                $command->idempotencyKey,
                $fingerprint,
                $open,
                static fn (PaymentSessionRow $session): array => ['session_id' => $session->id->value],
                fn (array $stored): PaymentSessionRow => $this->journal->getSession(new PaymentSessionId(
                    is_string($stored['session_id'] ?? null) ? $stored['session_id'] : throw new LogicException('Void receipt missing session_id.'),
                )),
            );
        });

        if (! $request instanceof GatewayVoidRequest) {
            return $this->journal->view($claimed->collection);
        }

        $attempt = $claimed->attempts;

        try {
            $result = $this->workflow->voidAtGateway($gatewayKey, $request);
        } catch (PaymentDeclined $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new PaymentOutcomeUnknown($claimed->id->value, $gatewayKey, $e);
        }

        if ($result->status !== GatewayPaymentStatus::Cancelled) {
            throw new InvalidGatewayResult($gatewayKey, "void must return status 'cancelled', got '{$result->status->value}'");
        }

        $this->journal->finishSession($claimed, $attempt, function (PaymentCollectionRow $collection, PaymentSessionRow $current) use ($result): PaymentSessionRow {
            $columns = [
                'status' => PaymentSessionStatus::Voided->value,
                'capture_closed' => true,
            ];

            if ($result->meta !== []) {
                $columns['meta'] = PaymentJournal::json($result->meta + $current->meta);
            }

            return $this->journal->updateSession($current->id, $columns);
        });

        return $this->journal->view($claimed->collection);
    }

    private function markSessionUnknown(PaymentSessionRow $session, int $attempt, Throwable $error): void
    {
        $this->journal->finishSession($session, $attempt, fn (PaymentCollectionRow $collection, PaymentSessionRow $current): PaymentSessionRow => $current->status === PaymentSessionStatus::Authorizing
            ? $this->journal->updateSession($current->id, ['status' => PaymentSessionStatus::Unknown->value] + PaymentJournal::error($error))
            : $current);
    }

    /** @throws InvalidGatewayResult */
    private function validateGatewayPaymentResult(PaymentSessionRow $session, string $gateway, GatewayPaymentResult $result): void
    {
        $reason = match (true) {
            $result->externalId !== null && ! self::isPrintable($result->externalId) => 'the external id must be 1..191 bytes of printable UTF-8',
            $result->externalId !== null && $session->externalId !== null && $result->externalId !== $session->externalId => 'the external id of the session changed',
            $result->externalId !== null && $this->journal->externalIdTaken($session, $result->externalId) => 'the external id belongs to another session',
            $result->authorized !== null && ! $result->authorized->currency->equals($session->currency) => 'the authorized amount currency does not match the session',
            $result->captured !== null && ! $result->captured->currency->equals($session->currency) => 'the captured amount currency does not match the session',
            $result->actionUrl !== null && (strlen($result->actionUrl) > 2048 || preg_match('#\Ahttps?://[^\s\x00-\x1F\x7F]+\z#i', $result->actionUrl) !== 1) => 'the action url must be an http(s) url of at most 2048 bytes',
            $result->status === GatewayPaymentStatus::RequiresAction && $result->actionUrl === null => 'an action is required without an action url',
            ! self::isMeta($result->meta) => 'meta must map string keys to scalars representable as PostgreSQL JSON without NUL',
            default => null,
        };

        if ($reason !== null) {
            throw new InvalidGatewayResult($gateway, $reason);
        }
    }

    /** @throws InvalidGatewayResult */
    private function validateGatewayCaptureResult(PaymentCaptureRow $capture, string $gateway, GatewayCaptureResult $result): void
    {
        $reason = match (true) {
            $result->externalId !== null && ! self::isPrintable($result->externalId) => 'the capture external id must be 1..191 bytes of printable UTF-8',
            ! $result->captured->currency->equals($capture->currency) => 'the captured amount currency does not match the capture request',
            $result->status === GatewayPaymentStatus::Captured && ! $result->captured->isPositive() => 'a captured result must report a positive captured amount',
            ! self::isMeta($result->meta) => 'meta must map string keys to scalars representable as PostgreSQL JSON without NUL',
            default => null,
        };

        if ($reason !== null) {
            throw new InvalidGatewayResult($gateway, $reason);
        }
    }

    private static function isPrintable(string $value): bool
    {
        return $value !== '' && strlen($value) <= 191 && mb_check_encoding($value, 'UTF-8') && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1;
    }

    /** @param  array<array-key, mixed>  $meta */
    private static function isMeta(array $meta): bool
    {
        foreach ($meta as $key => $value) {
            if (! is_string($key) || ! is_scalar($value) || str_contains($key, "\0") || (is_string($value) && str_contains($value, "\0"))) {
                return false;
            }
        }

        try {
            json_encode($meta, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return false;
        }

        return true;
    }
}
