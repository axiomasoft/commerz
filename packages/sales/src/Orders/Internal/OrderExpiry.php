<?php

declare(strict_types=1);

namespace Commerz\Sales\Orders\Internal;

use Commerz\Configuration\CommerzOptions;
use Commerz\Operations\Contracts\Transactions;
use Commerz\Sales\Common\Internal\OrderLocks;
use Commerz\Sales\Common\Internal\Rows;
use Commerz\Sales\Configuration\SalesOptions;
use Commerz\Sales\Orders\Data\ExpireOrders;
use Commerz\Sales\Orders\Data\ExpireReport;
use Commerz\Sales\Orders\Data\OrderId;
use Commerz\Sales\Orders\Enums\CancelReason;
use Commerz\Sales\Orders\Enums\OrderState;
use Commerz\Sales\Orders\Enums\PaymentState;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;
use Packman\Attributes\Internal;
use Packman\Schema\Data\Compiler\TableNames;

/**
 * Expiry of unpaid orders (D32): sales owns the payment deadline, the stock holds the reservation until then. Each
 * order expires in its own transaction under `for update skip locked`, so parallel passes share the work, and every
 * condition is checked again under the lock: an order with a payment under way, paid or partly fulfilled stays.
 */
#[Internal]
final readonly class OrderExpiry
{
    /** A payment in one of these states may still bring money: the order waits for it (as Saleor does). */
    private const array PAYMENTS_UNDER_WAY = ['initiating', 'pending', 'requires_action', 'unknown', 'authorized'];

    public function __construct(
        private ConnectionResolverInterface $connections,
        private CommerzOptions $config,
        private TableNames $tables,
        private Transactions $transactions,
        private SalesOptions $sales,
        private OrderLocks $locks,
        private OrderCancellation $cancellation,
    ) {}

    public function expireDue(ExpireOrders $command): ExpireReport
    {
        $at = $command->at ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $limit = $command->limit ?? $this->sales->expireBatch;
        $expired = [];
        $skipped = 0;
        $position = null;

        // Known non-expiring orders do not occupy the LIMIT. A keyset advances past rows that race or are locked,
        // so another worker holding the earliest candidate cannot starve every order behind it.
        while (count($expired) < $limit) {
            $candidates = $this->candidates($at, $limit - count($expired), $position);

            if ($candidates === []) {
                break;
            }

            foreach ($candidates as $candidate) {
                $order = new OrderId(Rows::string($candidate, 'id'));
                $position = [Rows::string($candidate, 'due_position'), $order->value];

                if ($this->transactions->run(fn (): bool => $this->expire($order, $at))) {
                    $expired[] = $order;
                } else {
                    $skipped++;
                }
            }
        }

        return new ExpireReport($expired, $skipped);
    }

    /**
     * Candidates are advisory; every condition is checked again under the order lock.
     *
     * @param  array{string, string}|null  $position  the last scanned (payment_due_at, id)
     * @return list<object>
     */
    private function candidates(DateTimeImmutable $at, int $limit, ?array $position): array
    {
        $orders = $this->tables->table('orders');
        $query = $this->db()->table($orders)
            ->select('id')
            ->selectRaw('payment_due_at::text as due_position')
            ->where('state', OrderState::Placed->value)
            ->where('payment_state', PaymentState::Unpaid->value)
            ->where('payment_due_at', '<=', $at->format('Y-m-d H:i:s.uP'))
            ->whereNotExists(function (Builder $query) use ($orders): void {
                $query->selectRaw('1')->from($this->tables->table('payments').' as p')
                    ->whereColumn('p.order_id', $orders.'.id')->whereIn('p.status', self::PAYMENTS_UNDER_WAY);
            })
            ->whereNotExists(function (Builder $query) use ($orders): void {
                $query->selectRaw('1')->from($this->tables->table('order_lines').' as l')
                    ->whereColumn('l.order_id', $orders.'.id')->where('l.fulfilled_quantity', '>', 0);
            })
            ->orderBy('payment_due_at')->orderBy('id')->limit($limit);

        if ($position !== null) {
            $query->whereRaw('(payment_due_at, id) > (?::timestamptz, ?::uuid)', $position);
        }

        return array_values($query->get()->all());
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
