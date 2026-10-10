<?php

declare(strict_types=1);

namespace Commerz\Sales\Orders\Internal;

use Commerz\Configuration\CommerzConfig;
use Commerz\Operations\Contracts\Transactions;
use Commerz\Sales\Common\Internal\OrderLocks;
use Commerz\Sales\Common\Internal\SalesConfig;
use Commerz\Sales\Orders\Data\ExpireOrders;
use Commerz\Sales\Orders\Data\ExpireReport;
use Commerz\Sales\Orders\Data\OrderId;
use Commerz\Sales\Orders\Enums\CancelReason;
use Commerz\Sales\Orders\Enums\OrderState;
use Commerz\Sales\Orders\Enums\PaymentState;
use Commerz\Schema\TableNames;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use LogicException;

/**
 * Expiry of unpaid orders (D32): sales owns the payment deadline, the stock holds the reservation until then. Each
 * order expires in its own transaction under `for update skip locked`, so parallel passes share the work, and every
 * condition is checked again under the lock: an order with a payment under way, paid or partly fulfilled stays.
 */
final readonly class OrderExpiry
{
    /** A payment in one of these states may still bring money: the order waits for it (as Saleor does). */
    private const array PAYMENTS_UNDER_WAY = ['initiating', 'pending', 'requires_action', 'unknown', 'authorized'];

    public function __construct(
        private ConnectionResolverInterface $connections,
        private CommerzConfig $config,
        private TableNames $tables,
        private Transactions $transactions,
        private SalesConfig $sales,
        private OrderLocks $locks,
        private OrderCancellation $cancellation,
    ) {}

    public function expireDue(ExpireOrders $command): ExpireReport
    {
        $at = $command->at ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));

        // Candidates are read without a lock; the lock of each order decides.
        $candidates = $this->db()->table($this->tables->table('orders'))
            ->where('state', OrderState::Placed->value)
            ->where('payment_state', PaymentState::Unpaid->value)
            ->where('payment_due_at', '<=', $at->format('Y-m-d H:i:s.uP'))
            ->orderBy('payment_due_at')
            ->orderBy('id')
            ->limit($command->limit ?? $this->sales->expireBatch)
            ->pluck('id')
            ->all();

        $expired = [];
        $skipped = 0;

        foreach ($candidates as $id) {
            $order = new OrderId(is_string($id) ? $id : throw new LogicException('An order id is not a string.'));

            if ($this->transactions->run(fn (): bool => $this->expire($order, $at))) {
                $expired[] = $order;
            } else {
                $skipped++;
            }
        }

        return new ExpireReport($expired, $skipped);
    }

    private function expire(OrderId $id, DateTimeImmutable $at): bool
    {
        $order = $this->locks->lockSkip($id);

        if ($order === null
            || $order->state !== OrderState::Placed
            || $order->paymentState !== PaymentState::Unpaid
            || $order->paymentDueAt === null
            || $order->paymentDueAt > $at
            || $order->fulfilled() > 0
            || $this->paymentUnderWay($id)) {
            return false;
        }

        $this->cancellation->cancelLocked($order, CancelReason::Expired, null);

        return true;
    }

    private function paymentUnderWay(OrderId $id): bool
    {
        return $this->db()->table($this->tables->table('payments'))
            ->where('order_id', $id->value)
            ->whereIn('status', self::PAYMENTS_UNDER_WAY)
            ->exists();
    }

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->config->connection);
    }
}
