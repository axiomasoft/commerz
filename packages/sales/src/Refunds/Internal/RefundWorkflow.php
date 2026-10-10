<?php

declare(strict_types=1);

namespace Commerz\Sales\Refunds\Internal;

use Commerz\Errors\UnsupportedContribution;
use Commerz\Money\Money;
use Commerz\Operations\Contracts\OperationReceipts;
use Commerz\Operations\Contracts\Transactions;
use Commerz\Operations\Fingerprint;
use Commerz\Plugins\Contracts\Contributions;
use Commerz\Sales\Common\Exceptions\OperationInsideTransaction;
use Commerz\Sales\Common\Internal\Lease;
use Commerz\Sales\Common\Internal\OrderLocks;
use Commerz\Sales\Common\Internal\OrderRow;
use Commerz\Sales\Common\Internal\SalesConfig;
use Commerz\Sales\Gateways\Contracts\PaymentGateway;
use Commerz\Sales\Gateways\Data\GatewayRefundRequest;
use Commerz\Sales\Gateways\Data\GatewayRefundResult;
use Commerz\Sales\Gateways\Enums\GatewayRefundStatus;
use Commerz\Sales\Gateways\Exceptions\InvalidGatewayResult;
use Commerz\Sales\Gateways\Exceptions\PaymentDeclined;
use Commerz\Sales\Refunds\Data\IssueRefund;
use Commerz\Sales\Refunds\Data\ReconcileRefund;
use Commerz\Sales\Refunds\Data\RefundView;
use Commerz\Sales\Refunds\Enums\RefundStatus;
use Commerz\Sales\Refunds\Exceptions\RefundInProgress;
use Commerz\Sales\Refunds\Exceptions\RefundOutcomeUnknown;
use LogicException;
use Throwable;

/**
 * The only caller of `PaymentGateway` for refunds (PAY-02, D33), the same protocol as `PaymentWorkflow`: the attempt is
 * written before the network, the gateway is called outside any transaction, an answer that is not certain is
 * `unknown` (never `failed`, so its amount is not refunded twice), and the answer is written in its own transaction
 * fenced by the attempt. `findRefund` recovers an unknown outcome at the gateway of the payment.
 */
final readonly class RefundWorkflow
{
    private const string SCOPE = 'sales.issue_refund';

    public function __construct(
        private Transactions $transactions,
        private OperationReceipts $receipts,
        private Contributions $contributions,
        private OrderLocks $locks,
        private RefundJournal $journal,
        private SalesConfig $config,
    ) {}

    public function issue(IssueRefund $command): RefundView
    {
        if ($this->transactions->inTransaction()) {
            throw new OperationInsideTransaction('sales.refunds.issue');
        }

        $request = null;

        // Tx A: the attempt and its request; a repeat of the key gets the attempt back and calls nothing.
        $refund = $this->transactions->run(function () use ($command, &$request): RefundRow {
            $fingerprint = Fingerprint::of([
                'order' => $command->order->value,
                'amount' => [$command->amount->amountMinor, $command->amount->currency->code],
                'payment' => $command->payment?->value,
                'return' => $command->return?->value,
                'reason' => $command->reason,
            ]);
            $open = function () use ($command, $fingerprint, &$request): RefundRow {
                $order = $this->locks->lock($command->order);
                $payment = $this->journal->payment($order, $command);

                if (! $this->contributions->has(PaymentGateway::KIND, $payment->gateway)) {
                    throw new UnsupportedContribution(PaymentGateway::KIND, $payment->gateway, 'the gateway of the payment is not installed');
                }

                $refund = $this->journal->open($order, $payment, $command, $fingerprint);
                $request = new GatewayRefundRequest(
                    $refund->reference,
                    $payment->reference,
                    $payment->externalId,
                    Money::ofMinor($refund->amount, $refund->currency),
                    $refund->reason,
                );

                return $refund;
            };

            if ($command->idempotencyKey === null) {
                return $open();
            }

            return $this->receipts->once(
                self::SCOPE,
                $command->idempotencyKey,
                $fingerprint,
                $open,
                static fn (RefundRow $refund): array => ['refund_id' => $refund->id->value],
                fn (array $stored): RefundRow => $this->journal->get(is_string($stored['refund_id'] ?? null) ? $stored['refund_id'] : throw new LogicException('A refund receipt holds no refund id.')),
            );
        });

        if (! $request instanceof GatewayRefundRequest) {
            // Initiating or unknown: the caller reconciles it.
            return $refund->view();
        }

        $gateway = $this->contributions->get(PaymentGateway::KIND, $refund->gateway, PaymentGateway::class);

        try {
            $result = $gateway->refund($request);
        } catch (PaymentDeclined $e) {
            $this->journal->finish($refund, 1, fn (OrderRow $order, RefundRow $current): RefundRow => $this->journal->write($order, $current, RefundStatus::Failed, RefundJournal::error($e), $command->actor));

            throw $e;
        } catch (Throwable $e) {
            $this->unknown($refund, 1, $e);

            throw new RefundOutcomeUnknown($refund->id->value, $refund->gateway, $e);
        }

        return $this->answered($refund, 1, $gateway, $result)->view();
    }

    public function reconcile(ReconcileRefund $command): RefundView
    {
        if ($this->transactions->inTransaction()) {
            throw new OperationInsideTransaction('sales.refunds.reconcile');
        }

        $stored = $this->journal->get($command->refund);

        // Tx A: a final refund has nothing to ask; a live issue is left alone; otherwise take the next attempt.
        $claimed = $this->transactions->run(function () use ($stored): RefundRow {
            $this->locks->lock($stored->order);
            $refund = $this->journal->get($stored->id);

            if ($refund->status->isFinal()) {
                return $refund;
            }

            if ($refund->status === RefundStatus::Initiating && Lease::isFresh($refund->claimedAt, $this->config->leaseSeconds)) {
                throw new RefundInProgress($refund->id->value);
            }

            if (! $this->contributions->has(PaymentGateway::KIND, $refund->gateway)) {
                throw new UnsupportedContribution(PaymentGateway::KIND, $refund->gateway, 'the gateway of the refund is not installed');
            }

            return $this->journal->lease($refund);
        });

        if ($claimed->status->isFinal()) {
            return $claimed->view();
        }

        $gateway = $this->contributions->get(PaymentGateway::KIND, $claimed->gateway, PaymentGateway::class);
        $attempt = $claimed->attempts;

        try {
            $found = $gateway->findRefund($claimed->reference);
        } catch (Throwable $e) {
            $this->unknown($claimed, $attempt, $e);

            throw new RefundOutcomeUnknown($claimed->id->value, $claimed->gateway, $e);
        }

        if ($found === null) {
            // The provider never made a refund it was not sure to have: it failed, and the amount may be refunded anew.
            if (in_array($claimed->status, [RefundStatus::Initiating, RefundStatus::Unknown], true)) {
                return $this->journal->finish($claimed, $attempt, fn (OrderRow $order, RefundRow $current): RefundRow => $this->journal->write(
                    $order,
                    $current,
                    RefundStatus::Failed,
                    RefundJournal::error('not_found_at_gateway', 'The gateway knows no such refund.'),
                ))->view();
            }

            throw new InvalidGatewayResult($gateway->key(), "refund '{$claimed->reference}' vanished at the gateway");
        }

        return $this->answered($claimed, $attempt, $gateway, $found)->view();
    }

    /** Validates and records an answer of the gateway; an invalid one leaves an initiating attempt unknown. */
    private function answered(RefundRow $refund, int $attempt, PaymentGateway $gateway, GatewayRefundResult $result): RefundRow
    {
        try {
            $this->validate($refund, $gateway->key(), $result);
        } catch (InvalidGatewayResult $e) {
            $this->unknown($refund, $attempt, $e);

            throw $e;
        }

        return $this->journal->finish($refund, $attempt, fn (OrderRow $order, RefundRow $current): RefundRow => $this->journal->write(
            $order,
            $current,
            match ($result->status) {
                GatewayRefundStatus::Pending => RefundStatus::Pending,
                GatewayRefundStatus::Succeeded => RefundStatus::Succeeded,
                GatewayRefundStatus::Failed => RefundStatus::Failed,
            },
            $result->externalId !== null && $current->externalId === null ? ['external_id' => $result->externalId] : [],
        ));
    }

    /** The answer was lost: an initiating attempt becomes unknown with the error; any other status stays. */
    private function unknown(RefundRow $refund, int $attempt, Throwable $error): void
    {
        $this->journal->finish($refund, $attempt, fn (OrderRow $order, RefundRow $current): RefundRow => $current->status === RefundStatus::Initiating
            ? $this->journal->write($order, $current, RefundStatus::Unknown, RefundJournal::error($error))
            : $current);
    }

    /**
     * Whether the answer fits the refund (sales-design#spi); the DTO checked only its own shape.
     *
     * @throws InvalidGatewayResult
     */
    private function validate(RefundRow $refund, string $gateway, GatewayRefundResult $result): void
    {
        $external = $result->externalId;
        $reason = match (true) {
            $external !== null && (! mb_check_encoding($external, 'UTF-8') || preg_match('/[\x00-\x1F\x7F]/', $external) === 1) => 'the external id must be 1..191 bytes of printable UTF-8',
            $external !== null && $refund->externalId !== null && $external !== $refund->externalId => 'the external id of the refund changed',
            $external !== null && $this->journal->externalIdTaken($refund, $external) => 'the external id belongs to another refund',
            default => null,
        };

        if ($reason !== null) {
            throw new InvalidGatewayResult($gateway, $reason);
        }
    }
}
