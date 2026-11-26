<?php

declare(strict_types=1);

namespace Commerz\Sales\Payments\Internal;

use Commerz\Money\Money;
use Commerz\Operations\Contracts\OperationReceipts;
use Commerz\Operations\Contracts\Transactions;
use Commerz\Operations\Fingerprint;
use Commerz\Sales\Common\Exceptions\OperationInsideTransaction;
use Commerz\Sales\Common\Internal\Lease;
use Commerz\Sales\Common\Internal\OrderLocks;
use Commerz\Sales\Common\Internal\OrderRow;
use Commerz\Sales\Configuration\SalesOptions;
use Commerz\Sales\Gateways\Contracts\AcquiringPaymentGateway;
use Commerz\Sales\Gateways\Contracts\PaymentGateway;
use Commerz\Sales\Gateways\Data\GatewayAuthorizeRequest;
use Commerz\Sales\Gateways\Data\GatewayCaptureRequest;
use Commerz\Sales\Gateways\Data\GatewayCaptureResult;
use Commerz\Sales\Gateways\Data\GatewayIncrementalAuthRequest;
use Commerz\Sales\Gateways\Data\GatewayPaymentRequest;
use Commerz\Sales\Gateways\Data\GatewayPaymentResult;
use Commerz\Sales\Gateways\Data\GatewayVoidRequest;
use Commerz\Sales\Gateways\Data\PaymentCapabilities;
use Commerz\Sales\Gateways\Enums\GatewayPaymentStatus;
use Commerz\Sales\Gateways\Exceptions\GatewayCapabilityUnsupported;
use Commerz\Sales\Gateways\Exceptions\InvalidGatewayResult;
use Commerz\Sales\Gateways\Exceptions\PaymentDeclined;
use Commerz\Sales\Payments\Data\InitiatePayment;
use Commerz\Sales\Payments\Data\PaymentView;
use Commerz\Sales\Payments\Data\ReconcilePayment;
use Commerz\Sales\Payments\Enums\PaymentStatus;
use Commerz\Sales\Payments\Exceptions\PaymentInProgress;
use Commerz\Sales\Payments\Exceptions\PaymentOutcomeUnknown;
use JsonException;
use LogicException;
use Packman\Attributes\Internal;
use Packman\Plugins\Contracts\Contributions\Contributions;
use Packman\Plugins\Exceptions\Registry\UnsupportedContribution;
use Throwable;

/**
 * The only caller of `PaymentGateway` for payments (PAY-02, D33, as D29 for bookings): the attempt is written before
 * the network, the gateway is called outside any transaction, an answer that is not certain is `unknown` (never
 * `failed`), and the answer is written in its own transaction fenced by the attempt. `find` recovers an unknown
 * outcome, at the gateway the payment was made at, not the one configured now.
 */
#[Internal]
final readonly class PaymentWorkflow
{
    private const string SCOPE = 'sales.initiate_payment';

    public function __construct(
        private Transactions $transactions,
        private OperationReceipts $receipts,
        private Contributions $contributions,
        private OrderLocks $locks,
        private PaymentJournal $journal,
        private SalesOptions $config,
    ) {}

    public function initiate(InitiatePayment $command): PaymentView
    {
        if ($this->transactions->inTransaction()) {
            throw new OperationInsideTransaction('sales.payments.initiate');
        }

        $gateway = $this->contributions->get(PaymentGateway::KIND, $command->gateway, PaymentGateway::class);
        $request = null;

        // Tx A: the attempt and its request; a repeat of the key gets the attempt back and calls nothing.
        $payment = $this->transactions->run(function () use ($command, &$request): PaymentRow {
            $fingerprint = Fingerprint::of([
                'order' => $command->order->value,
                'gateway' => $command->gateway,
                'amount' => $command->amount === null ? null : [$command->amount->amountMinor, $command->amount->currency->code],
                'meta' => $command->meta,
            ]);
            $open = function () use ($command, $fingerprint, &$request): PaymentRow {
                $order = $this->locks->lock($command->order);
                $payment = $this->journal->open($order, $command, $fingerprint);
                $request = $this->request($order, $payment, $command);

                return $payment;
            };

            if ($command->idempotencyKey === null) {
                return $open();
            }

            return $this->receipts->once(
                self::SCOPE,
                $command->idempotencyKey,
                $fingerprint,
                $open,
                static fn (PaymentRow $payment): array => ['payment_id' => $payment->id->value],
                fn (array $stored): PaymentRow => $this->journal->get(is_string($stored['payment_id'] ?? null) ? $stored['payment_id'] : throw new LogicException('A payment receipt holds no payment id.')),
            );
        });

        if (! $request instanceof GatewayPaymentRequest) {
            // Initiating or unknown: the caller reconciles it.
            return $payment->view();
        }

        try {
            $result = $gateway->initiate($request);
        } catch (PaymentDeclined $e) {
            $this->journal->finish($payment, 1, fn (OrderRow $order, PaymentRow $current): PaymentRow => $this->failed($order, $current, PaymentJournal::error($e)));

            throw $e;
        } catch (Throwable $e) {
            $this->unknown($payment, 1, $e);

            throw new PaymentOutcomeUnknown($payment->id->value, $payment->gateway, $e);
        }

        return $this->answered($payment, 1, $gateway, $result)->view();
    }

    public function reconcile(ReconcilePayment $command): PaymentView
    {
        if ($this->transactions->inTransaction()) {
            throw new OperationInsideTransaction('sales.payments.reconcile');
        }

        $stored = $this->journal->get($command->payment->value);

        // Tx A: a final payment has nothing to ask; a live initiate is left alone; otherwise take the next attempt.
        $claimed = $this->transactions->run(function () use ($stored): PaymentRow {
            $this->locks->lock($stored->order);
            $payment = $this->journal->lock($stored->id);

            if ($payment->status->isFinal()) {
                return $payment;
            }

            if ($payment->status === PaymentStatus::Initiating && Lease::isFresh($payment->claimedAt, $this->config->leaseSeconds)) {
                throw new PaymentInProgress($payment->id->value);
            }

            if (! $this->contributions->has(PaymentGateway::KIND, $payment->gateway)) {
                throw new UnsupportedContribution(PaymentGateway::KIND, $payment->gateway, 'the gateway of the payment is not installed');
            }

            return $this->journal->lease($payment);
        });

        if ($claimed->status->isFinal()) {
            return $claimed->view();
        }

        $gateway = $this->contributions->get(PaymentGateway::KIND, $claimed->gateway, PaymentGateway::class);
        $attempt = $claimed->attempts;

        try {
            $found = $gateway->find($claimed->reference);
        } catch (Throwable $e) {
            $this->unknown($claimed, $attempt, $e);

            throw new PaymentOutcomeUnknown($claimed->id->value, $claimed->gateway, $e);
        }

        if ($found === null) {
            // The provider never created an attempt it was not sure to have: it failed, and the order may be paid anew.
            if (in_array($claimed->status, [PaymentStatus::Initiating, PaymentStatus::Unknown], true)) {
                return $this->journal->finish($claimed, $attempt, fn (OrderRow $order, PaymentRow $current): PaymentRow => $this->failed(
                    $order,
                    $current,
                    PaymentJournal::error('not_found_at_gateway', 'The gateway knows no such payment.'),
                ))->view();
            }

            throw new InvalidGatewayResult($gateway->key(), "payment '{$claimed->reference}' vanished at the gateway");
        }

        return $this->answered($claimed, $attempt, $gateway, $found)->view();
    }

    public function assertNotInTransaction(string $operation): void
    {
        if ($this->transactions->inTransaction()) {
            throw new OperationInsideTransaction($operation);
        }
    }

    public function capabilitiesOf(string $gatewayKey): PaymentCapabilities
    {
        $gateway = $this->contributions->get(PaymentGateway::KIND, $gatewayKey, PaymentGateway::class);

        return PaymentCapabilities::of($gateway);
    }

    public function validateCapabilityForAuthorize(string $gatewayKey, bool $autoCapture, bool $hasLineItems): PaymentCapabilities
    {
        $gateway = $this->contributions->get(PaymentGateway::KIND, $gatewayKey, PaymentGateway::class);
        $capabilities = PaymentCapabilities::of($gateway);

        if (! $autoCapture && (! $gateway instanceof AcquiringPaymentGateway || ! $capabilities->authorizeCaptureVoid)) {
            throw new GatewayCapabilityUnsupported($gatewayKey, 'authorizeCaptureVoid');
        }

        if ($hasLineItems && ! $capabilities->lineItemsL3) {
            throw new GatewayCapabilityUnsupported($gatewayKey, 'lineItemsL3');
        }

        return $capabilities;
    }

    public function validateCapabilityForCapture(string $gatewayKey, bool $isFinalCapture, bool $isSubsequentCapture, bool $hasLineItems): PaymentCapabilities
    {
        $gateway = $this->contributions->get(PaymentGateway::KIND, $gatewayKey, PaymentGateway::class);
        $capabilities = PaymentCapabilities::of($gateway);

        if (! $gateway instanceof AcquiringPaymentGateway || ! $capabilities->authorizeCaptureVoid) {
            throw new GatewayCapabilityUnsupported($gatewayKey, 'authorizeCaptureVoid');
        }

        if ((! $isFinalCapture || $isSubsequentCapture) && ! $capabilities->multicapture) {
            throw new GatewayCapabilityUnsupported($gatewayKey, 'multicapture');
        }

        if ($hasLineItems && ! $capabilities->lineItemsL3) {
            throw new GatewayCapabilityUnsupported($gatewayKey, 'lineItemsL3');
        }

        return $capabilities;
    }

    public function validateCapabilityForIncrementalAuth(string $gatewayKey): PaymentCapabilities
    {
        $gateway = $this->contributions->get(PaymentGateway::KIND, $gatewayKey, PaymentGateway::class);
        $capabilities = PaymentCapabilities::of($gateway);

        if (! $gateway instanceof AcquiringPaymentGateway || ! $capabilities->incrementalAuth) {
            throw new GatewayCapabilityUnsupported($gatewayKey, 'incrementalAuth');
        }

        return $capabilities;
    }

    public function validateCapabilityForVoid(string $gatewayKey): PaymentCapabilities
    {
        $gateway = $this->contributions->get(PaymentGateway::KIND, $gatewayKey, PaymentGateway::class);
        $capabilities = PaymentCapabilities::of($gateway);

        if (! $gateway instanceof AcquiringPaymentGateway || ! $capabilities->authorizeCaptureVoid) {
            throw new GatewayCapabilityUnsupported($gatewayKey, 'authorizeCaptureVoid');
        }

        return $capabilities;
    }

    public function authorizeAtGateway(string $gatewayKey, GatewayAuthorizeRequest $request, bool $autoCapture): GatewayPaymentResult
    {
        $this->assertNotInTransaction('sales.payment_collections.authorize');
        $gateway = $this->contributions->get(PaymentGateway::KIND, $gatewayKey, PaymentGateway::class);
        $capabilities = PaymentCapabilities::of($gateway);

        if ($request->lineItems !== [] && ! $capabilities->lineItemsL3) {
            throw new GatewayCapabilityUnsupported($gatewayKey, 'lineItemsL3');
        }

        if ($autoCapture) {
            return $gateway->initiate(new GatewayPaymentRequest(
                reference: $request->reference,
                orderRef: $request->targetRef,
                orderNumber: $request->orderNumber ?? $request->targetRef,
                amount: $request->amount,
                customer: $request->customer,
                customerEmail: $request->customerEmail,
                meta: $request->meta,
            ));
        }

        if (! $gateway instanceof AcquiringPaymentGateway || ! $capabilities->authorizeCaptureVoid) {
            throw new GatewayCapabilityUnsupported($gatewayKey, 'authorizeCaptureVoid');
        }

        return $gateway->authorize($request);
    }

    public function captureAtGateway(string $gatewayKey, GatewayCaptureRequest $request, bool $isSubsequentCapture, bool $isOvercapture): GatewayCaptureResult
    {
        $this->assertNotInTransaction('sales.payment_collections.capture');
        $gateway = $this->contributions->get(PaymentGateway::KIND, $gatewayKey, PaymentGateway::class);
        $capabilities = PaymentCapabilities::of($gateway);

        if (! $gateway instanceof AcquiringPaymentGateway || ! $capabilities->authorizeCaptureVoid) {
            throw new GatewayCapabilityUnsupported($gatewayKey, 'authorizeCaptureVoid');
        }

        if ((! $request->isFinalCapture || $isSubsequentCapture) && ! $capabilities->multicapture) {
            throw new GatewayCapabilityUnsupported($gatewayKey, 'multicapture');
        }

        if ($isOvercapture && ! $capabilities->overcapture) {
            throw new GatewayCapabilityUnsupported($gatewayKey, 'overcapture');
        }

        if ($request->lineItems !== [] && ! $capabilities->lineItemsL3) {
            throw new GatewayCapabilityUnsupported($gatewayKey, 'lineItemsL3');
        }

        return $gateway->capture($request);
    }

    public function incrementalAuthorizeAtGateway(string $gatewayKey, GatewayIncrementalAuthRequest $request): GatewayPaymentResult
    {
        $this->assertNotInTransaction('sales.payment_collections.incremental_authorize');
        $gateway = $this->contributions->get(PaymentGateway::KIND, $gatewayKey, PaymentGateway::class);
        $capabilities = PaymentCapabilities::of($gateway);

        if (! $gateway instanceof AcquiringPaymentGateway || ! $capabilities->incrementalAuth) {
            throw new GatewayCapabilityUnsupported($gatewayKey, 'incrementalAuth');
        }

        return $gateway->incrementalAuthorize($request);
    }

    public function voidAtGateway(string $gatewayKey, GatewayVoidRequest $request): GatewayPaymentResult
    {
        $this->assertNotInTransaction('sales.payment_collections.void');
        $gateway = $this->contributions->get(PaymentGateway::KIND, $gatewayKey, PaymentGateway::class);
        $capabilities = PaymentCapabilities::of($gateway);

        if (! $gateway instanceof AcquiringPaymentGateway || ! $capabilities->authorizeCaptureVoid) {
            throw new GatewayCapabilityUnsupported($gatewayKey, 'authorizeCaptureVoid');
        }

        return $gateway->void($request);
    }

    /** Validates and records an answer of the gateway; an invalid one leaves an initiating attempt unknown. */
    private function answered(PaymentRow $payment, int $attempt, PaymentGateway $gateway, GatewayPaymentResult $result): PaymentRow
    {
        try {
            $this->validate($payment, $gateway->key(), $result);
        } catch (InvalidGatewayResult $e) {
            $this->unknown($payment, $attempt, $e);

            throw $e;
        }

        return $this->journal->finish($payment, $attempt, function (OrderRow $order, PaymentRow $current) use ($result): PaymentRow {
            $amount = match ($result->status) {
                GatewayPaymentStatus::Captured => $result->captured,
                GatewayPaymentStatus::Authorized => $result->authorized,
                default => null,
            };
            $next = PaymentTransitions::apply($current, self::observed($result->status), $amount?->amountMinor, $result->actionUrl);
            $columns = [];

            if ($result->externalId !== null && $current->externalId === null) {
                $columns['external_id'] = $result->externalId;
            }

            if ($result->meta !== []) {
                $columns['meta'] = PaymentJournal::json($result->meta + $current->meta);
            }

            return $this->journal->write($order, $current, $next ?? $current, $columns);
        });
    }

    /** @param  array{error_code: string, error_message: string}  $error */
    private function failed(OrderRow $order, PaymentRow $current, array $error): PaymentRow
    {
        $next = PaymentTransitions::apply($current, PaymentStatus::Failed);

        return $next === null ? $current : $this->journal->write($order, $current, $next, $error);
    }

    /** The answer was lost: an initiating attempt becomes unknown with the error; any other status stays. */
    private function unknown(PaymentRow $payment, int $attempt, Throwable $error): void
    {
        $this->journal->finish($payment, $attempt, fn (OrderRow $order, PaymentRow $current): PaymentRow => $current->status === PaymentStatus::Initiating
            ? $this->journal->write($order, $current, $current->with(PaymentStatus::Unknown, $current->authorized, $current->captured, $current->actionUrl), PaymentJournal::error($error))
            : $current);
    }

    private function request(OrderRow $order, PaymentRow $payment, InitiatePayment $command): GatewayPaymentRequest
    {
        [$customer, $email] = $this->journal->customer($order);

        return new GatewayPaymentRequest(
            $payment->reference,
            'order:'.$order->id->value,
            $order->number,
            Money::ofMinor($payment->amount, $payment->currency),
            $customer,
            $email,
            $command->meta,
        );
    }

    /**
     * Whether the answer fits the attempt (sales-design#spi); the DTO checked only its own shape.
     *
     * @throws InvalidGatewayResult
     */
    private function validate(PaymentRow $payment, string $gateway, GatewayPaymentResult $result): void
    {
        $reason = match (true) {
            $result->externalId !== null && ! self::isPrintable($result->externalId) => 'the external id must be 1..191 bytes of printable UTF-8',
            $result->externalId !== null && $payment->externalId !== null && $result->externalId !== $payment->externalId => 'the external id of the payment changed',
            $result->externalId !== null && $this->journal->externalIdTaken($payment, $result->externalId) => 'the external id belongs to another payment',
            ! self::fits($result->authorized, $payment) => 'the authorized amount is not within the payment in its currency',
            ! self::fits($result->captured, $payment) => 'the captured amount is not within the payment in its currency',
            $result->actionUrl !== null && (strlen($result->actionUrl) > 2048 || preg_match('#\Ahttps?://[^\s\x00-\x1F\x7F]+\z#i', $result->actionUrl) !== 1) => 'the action url must be an http(s) url of at most 2048 bytes',
            $result->status === GatewayPaymentStatus::RequiresAction && $result->actionUrl === null => 'an action is required without an action url',
            ! self::isMeta($result->meta) => 'meta must map string keys to scalars representable as PostgreSQL JSON without NUL',
            default => null,
        };

        if ($reason !== null) {
            throw new InvalidGatewayResult($gateway, $reason);
        }
    }

    private static function observed(GatewayPaymentStatus $status): PaymentStatus
    {
        return match ($status) {
            GatewayPaymentStatus::Pending => PaymentStatus::Pending,
            GatewayPaymentStatus::RequiresAction => PaymentStatus::RequiresAction,
            GatewayPaymentStatus::Authorized => PaymentStatus::Authorized,
            GatewayPaymentStatus::Captured => PaymentStatus::Captured,
            GatewayPaymentStatus::Failed => PaymentStatus::Failed,
            GatewayPaymentStatus::Cancelled => PaymentStatus::Cancelled,
        };
    }

    private static function fits(?Money $amount, PaymentRow $payment): bool
    {
        return $amount === null
            || ($amount->currency->equals($payment->currency) && ! $amount->isNegative() && $amount->amountMinor <= $payment->amount);
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
