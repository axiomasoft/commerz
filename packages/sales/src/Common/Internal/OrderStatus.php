<?php

declare(strict_types=1);

namespace Commerz\Sales\Common\Internal;

use Commerz\Configuration\CommerzOptions;
use Commerz\Identity\ActorRef;
use Commerz\Money\Money;
use Commerz\Money\MoneyAllocator;
use Commerz\Operations\Contracts\CommittedEvents;
use Commerz\Outbox\Contracts\Outbox;
use Commerz\Outbox\Data\IntegrationEvent;
use Commerz\Sales\Orders\Enums\CancelReason;
use Commerz\Sales\Orders\Enums\FulfillmentState;
use Commerz\Sales\Orders\Enums\OrderState;
use Commerz\Sales\Orders\Enums\PaymentState;
use Commerz\Sales\Orders\Events\OrderCancelled;
use Commerz\Sales\Orders\Events\OrderCompleted;
use Commerz\Sales\Orders\Events\OrderPaymentStateChanged;
use Commerz\Sales\Orders\Events\PaymentRequiresRefund;
use Commerz\Sales\Orders\Exceptions\OrderNotFound;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use LogicException;
use Packman\Attributes\Internal;
use Packman\Schema\Data\Compiler\TableNames;

/**
 * The only writer of the states and amounts of an order (D31): `amount_due`, `authorized`, `captured`, `refunded`, the
 * three states, `completed_at` and `cancelled_at`. Every change of the ledger, the payments or the refunds of an order
 * ends with `refresh`, under the lock of the order and in the same transaction, so the states never drift from the money
 * and the lines. A change of state is journalled, recorded in the outbox and announced after commit.
 */
#[Internal]
final readonly class OrderStatus
{
    public function __construct(
        private ConnectionResolverInterface $connections,
        private CommerzOptions $config,
        private TableNames $tables,
        private OrderJournal $journal,
        private Outbox $outbox,
        private CommittedEvents $events,
    ) {}

    /**
     * Derives the states from the stored lines, payments and refunds and writes what changed. `$reason` is required
     * when the order becomes cancelled (every unit cancelled); `completed` and `cancelled` are final.
     */
    public function refresh(OrderRow $order, ?CancelReason $reason = null, ?ActorRef $actor = null): OrderStatusChange
    {
        $db = $this->db();
        $orders = $this->tables->table('orders');
        $id = $order->id->value;
        // Under the lock the caller took; read again, since the ledger has changed since that lock.
        $stored = $db->table($orders)->where('id', $id)->lockForUpdate()->first() ?? throw new OrderNotFound($id);
        $currency = $order->currency;

        $quantity = $cancelled = $fulfilled = $returned = $cancelledValue = 0;

        foreach ($db->table($this->tables->table('order_lines'))->where('order_id', $id)->get() as $line) {
            $quantity += Rows::int($line, 'quantity');
            $cancelled += Rows::int($line, 'cancelled_quantity');
            $fulfilled += Rows::int($line, 'fulfilled_quantity');
            $returned += Rows::int($line, 'returned_quantity');
            $cancelledValue += self::cancelledValue(
                Money::ofMinor(Rows::int($line, 'total_minor'), $currency),
                Rows::int($line, 'quantity'),
                Rows::int($line, 'cancelled_quantity'),
            )->amountMinor;
        }

        $payments = $db->table($this->tables->table('payments'))->where('order_id', $id)
            ->selectRaw("coalesce(sum(authorized_minor) filter (where status = 'authorized'), 0)::bigint as authorized")
            ->selectRaw('coalesce(sum(captured_minor), 0)::bigint as captured')
            ->first();
        $refunds = $db->table($this->tables->table('refunds'))->where('order_id', $id)
            ->selectRaw("coalesce(sum(amount_minor) filter (where status = 'succeeded'), 0)::bigint as refunded")
            ->first();
        $authorized = $payments === null ? 0 : Rows::int($payments, 'authorized');
        $captured = $payments === null ? 0 : Rows::int($payments, 'captured');
        $refunded = $refunds === null ? 0 : Rows::int($refunds, 'refunded');

        $from = OrderState::from(Rows::string($stored, 'state'));
        $paymentFrom = PaymentState::from(Rows::string($stored, 'payment_state'));
        $fulfillmentFrom = FulfillmentState::from(Rows::string($stored, 'fulfillment_state'));
        // A cancelled order owes nothing, its delivery included; otherwise the total less the value of the cancelled units.
        $amountDue = self::cancels($from, $quantity, $cancelled) ? 0 : Rows::int($stored, 'total_minor') - $cancelledValue;

        [$state, $payment, $fulfillment] = self::derive($from, $quantity, $cancelled, $fulfilled, $returned, $amountDue, $authorized, $captured, $refunded);

        $now = Clock::now();
        $values = [
            'amount_due_minor' => $amountDue,
            'authorized_minor' => $authorized,
            'captured_minor' => $captured,
            'refunded_minor' => $refunded,
            'state' => $state->value,
            'payment_state' => $payment->value,
            'fulfillment_state' => $fulfillment->value,
        ];

        if ($state !== $from && $state === OrderState::Cancelled) {
            $values['cancel_reason'] = ($reason ?? throw new LogicException("Order '{$id}' is cancelled without a reason."))->value;
            $values['cancelled_at'] = $now;
        }

        if ($state !== $from && $state === OrderState::Completed) {
            $values['completed_at'] = $now;
        }

        $changes = array_filter($values, static fn (int|string $value, string $column): bool => $stored->{$column} !== $value, ARRAY_FILTER_USE_BOTH);

        if ($changes !== []) {
            $db->table($orders)->where('id', $id)->update($changes + ['updated_at' => $now]);
        }

        $change = new OrderStatusChange($from, $state, $paymentFrom, $payment, $fulfillmentFrom, $fulfillment);
        $owedBefore = max(0, Rows::int($stored, 'captured_minor') - Rows::int($stored, 'refunded_minor') - Rows::int($stored, 'amount_due_minor'));
        $this->announce($order, $change, $values, $reason, $actor, max(0, $captured - $refunded - $amountDue) - $owedBefore);

        return $change;
    }

    /**
     * The three states from the sums of the order (sales-design#model): `$quantity`, `$cancelled`, `$fulfilled` and
     * `$returned` over all lines, amounts in minor units. Only a placed order changes its own state.
     *
     * @return array{OrderState, PaymentState, FulfillmentState}
     */
    public static function derive(
        OrderState $current,
        int $quantity,
        int $cancelled,
        int $fulfilled,
        int $returned,
        int $amountDue,
        int $authorized,
        int $captured,
        int $refunded,
    ): array {
        $cancels = self::cancels($current, $quantity, $cancelled);
        $outstanding = $quantity - $cancelled - $fulfilled;

        $payment = match (true) {
            $refunded > 0 => $refunded >= $captured ? PaymentState::Refunded : PaymentState::PartiallyRefunded,
            $captured > 0 && $captured >= $amountDue => PaymentState::Paid,
            // Nothing to pay (a free order) is paid; a cancelled order without money stays unpaid.
            $amountDue === 0 && ! $cancels => PaymentState::Paid,
            $captured > 0 => PaymentState::PartiallyPaid,
            $authorized > 0 => PaymentState::Authorized,
            default => PaymentState::Unpaid,
        };

        $fulfillment = match (true) {
            $returned > 0 => $returned === $fulfilled && $outstanding === 0 ? FulfillmentState::Returned : FulfillmentState::PartiallyReturned,
            $fulfilled === 0 => FulfillmentState::Unfulfilled,
            $outstanding > 0 => FulfillmentState::PartiallyFulfilled,
            default => FulfillmentState::Fulfilled,
        };

        $state = match (true) {
            $current !== OrderState::Placed => $current,
            $cancels => OrderState::Cancelled,
            // A refund describes money given back, not proof that the accepted amount was ever paid.
            $outstanding === 0 && $fulfilled > 0 && $captured >= $amountDue => OrderState::Completed,
            default => OrderState::Placed,
        };

        return [$state, $payment, $fulfillment];
    }

    /** The exact share of the line total that its cancelled units take (largest remainder), the whole total when all are cancelled. */
    public static function cancelledValue(Money $total, int $quantity, int $cancelled): Money
    {
        if ($cancelled === 0) {
            return Money::zero($total->currency);
        }

        return MoneyAllocator::allocate($total, [$quantity - $cancelled, $cancelled])[1];
    }

    private static function cancels(OrderState $current, int $quantity, int $cancelled): bool
    {
        return $current === OrderState::Cancelled || ($current === OrderState::Placed && $cancelled === $quantity);
    }

    /** @param  array<string, int|string>  $values */
    private function announce(OrderRow $order, OrderStatusChange $change, array $values, ?CancelReason $reason, ?ActorRef $actor, int $owed): void
    {
        $id = $order->id;
        $ref = 'order:'.$id->value;
        $currency = $order->currency->code;
        $amounts = [
            'amount_due_minor' => $values['amount_due_minor'],
            'captured_minor' => $values['captured_minor'],
            'refunded_minor' => $values['refunded_minor'],
            'currency' => $currency,
        ];

        if ($change->fulfillment !== $change->fulfillmentFrom) {
            $this->journal->append($id, 'fulfillment_state_changed', $actor, ['from' => $change->fulfillmentFrom->value, 'to' => $change->fulfillment->value]);
        }

        if ($change->payment !== $change->paymentFrom) {
            $this->journal->append($id, 'payment_state_changed', $actor, ['from' => $change->paymentFrom->value, 'to' => $change->payment->value]);
            $this->outbox->record(IntegrationEvent::of('sales.payment_state_changed', $ref, [
                'order_id' => $id->value,
                'number' => $order->number,
                'from' => $change->paymentFrom->value,
                'to' => $change->payment->value,
            ] + $amounts));
            $this->events->dispatch(new OrderPaymentStateChanged($id, $change->paymentFrom, $change->payment));
        }

        if ($change->state !== $change->stateFrom && $change->state === OrderState::Completed) {
            $this->journal->append($id, 'completed', $actor);
            $this->outbox->record(IntegrationEvent::of('sales.order_completed', $ref, ['order_id' => $id->value, 'number' => $order->number]));
            $this->events->dispatch(new OrderCompleted($id));
        }

        if ($change->state !== $change->stateFrom && $change->state === OrderState::Cancelled && $reason !== null) {
            $this->journal->append($id, 'cancelled', $actor, ['reason' => $reason->value]);
            $this->outbox->record(IntegrationEvent::of('sales.order_cancelled', $ref, [
                'order_id' => $id->value,
                'number' => $order->number,
                'reason' => $reason->value,
            ] + $amounts));
            $this->events->dispatch(new OrderCancelled($id, $reason));
        }

        // Money held over the amount due grew: a refund is needed, and it is never lost silently.
        if ($owed > 0) {
            $this->journal->append($id, 'payment_requires_refund', $actor, ['amount_minor' => $owed, 'currency' => $currency]);
            $this->outbox->record(IntegrationEvent::of('sales.payment_requires_refund', $ref, [
                'order_id' => $id->value,
                'number' => $order->number,
                'amount_minor' => $owed,
                'currency' => $currency,
            ]));
            $this->events->dispatch(new PaymentRequiresRefund($id, Money::ofMinor($owed, $order->currency)));
        }
    }

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->config->connection);
    }
}
