<?php

declare(strict_types=1);

namespace Commerz\Sales\Orders\Internal;

use Commerz\Identity\ActorRef;
use Commerz\Operations\Contracts\Transactions;
use Commerz\Ports\Promotions\Contracts\PromotionUsage;
use Commerz\Ports\Promotions\Data\RestorePromotionUsage;
use Commerz\Ports\Promotions\Enums\RestoreReason;
use Commerz\Ports\Sellables\Enums\SellableKind;
use Commerz\Ports\Stock\Contracts\StockReservations;
use Commerz\Ports\Stock\Data\ReleaseReservation;
use Commerz\Sales\Common\Data\LineQuantity;
use Commerz\Sales\Common\Exceptions\LineNotFound;
use Commerz\Sales\Common\Internal\OrderJournal;
use Commerz\Sales\Common\Internal\OrderLedger;
use Commerz\Sales\Common\Internal\OrderLineRow;
use Commerz\Sales\Common\Internal\OrderLocks;
use Commerz\Sales\Common\Internal\OrderRow;
use Commerz\Sales\Common\Internal\OrderRows;
use Commerz\Sales\Common\Internal\OrderStatus;
use Commerz\Sales\Orders\Data\CancelOrder;
use Commerz\Sales\Orders\Data\CancelOrderLines;
use Commerz\Sales\Orders\Data\OrderView;
use Commerz\Sales\Orders\Enums\CancelReason;
use Commerz\Sales\Orders\Enums\OrderState;
use Commerz\Sales\Orders\Exceptions\OrderNotCancellable;

/**
 * Cancellation of a whole order and of some of its units (sales-design#lifecycle). In the global lock order: the order
 * `for update` → the ledger → stock (release) → promotion usage (restore) → the state, which also announces the refund
 * a paid order now needs. Nothing is refunded automatically.
 */
final readonly class OrderCancellation
{
    public function __construct(
        private Transactions $transactions,
        private OrderLocks $locks,
        private OrderLedger $ledger,
        private OrderStatus $status,
        private OrderJournal $journal,
        private OrderRows $rows,
        private StockReservations $stock,
        private PromotionUsage $usage,
    ) {}

    /** @throws OrderNotCancellable */
    public function cancel(CancelOrder $command): OrderView
    {
        return $this->transactions->run(function () use ($command): OrderView {
            $this->cancelLocked($this->locks->lock($command->order), $command->reason, $command->actor);

            return $this->rows->get($command->order->value);
        });
    }

    /** @throws OrderNotCancellable|LineNotFound */
    public function cancelLines(CancelOrderLines $command): OrderView
    {
        return $this->transactions->run(function () use ($command): OrderView {
            $order = $this->locks->lock($command->order);

            if ($order->state !== OrderState::Placed) {
                throw new OrderNotCancellable($order->id->value, $order->state->value);
            }

            // Lines in id order, as every writer of several lines takes them.
            $lines = $command->lines;
            usort($lines, static fn (LineQuantity $a, LineQuantity $b): int => strcmp($a->lineId->value, $b->lineId->value));
            $rows = [];

            foreach ($lines as $line) {
                $rows[] = $order->line($line->lineId->value) ?? throw new LineNotFound($line->lineId->value);
                $this->ledger->cancel($order->id, $line);
                $this->journal->append($order->id, 'line_cancelled', $command->actor, [
                    'line_id' => $line->lineId->value,
                    'quantity' => $line->quantity,
                    'reason' => $command->reason->value,
                ]);
            }

            $requested = array_sum(array_map(static fn (LineQuantity $line): int => $line->quantity, $lines));

            // Every unit left is cancelled: that is the cancellation of the order.
            if ($requested === $order->outstanding() && $order->fulfilled() === 0) {
                $this->close($order, $command->reason, $command->actor);

                return $this->rows->get($order->id->value);
            }

            foreach ($lines as $index => $line) {
                if ($rows[$index]->kind === SellableKind::Physical) {
                    $this->stock->release(new ReleaseReservation(self::demand($order), $rows[$index]->sellable, $line->quantity));
                }
            }

            $this->status->refresh($order, actor: $command->actor);

            return $this->rows->get($order->id->value);
        });
    }

    /**
     * Cancels every outstanding unit of an order locked by the caller. A cancelled order is left as it is.
     *
     * @throws OrderNotCancellable
     */
    public function cancelLocked(OrderRow $order, CancelReason $reason, ?ActorRef $actor): void
    {
        if ($order->state === OrderState::Cancelled) {
            return;
        }

        if ($order->state === OrderState::Completed) {
            throw new OrderNotCancellable($order->id->value, 'completed');
        }

        // A partly fulfilled order keeps what was shipped: only its lines can be cancelled.
        if ($order->fulfilled() > 0) {
            throw new OrderNotCancellable($order->id->value, 'fulfilled');
        }

        foreach ($order->lines as $line) {
            if ($line->outstanding() > 0) {
                $this->ledger->cancel($order->id, new LineQuantity($line->id, $line->outstanding()));
            }
        }

        $this->close($order, $reason, $actor);
    }

    /** Gives back the stock and the promotion usage of an order whose units are all cancelled, and cancels it. */
    private function close(OrderRow $order, CancelReason $reason, ?ActorRef $actor): void
    {
        if (array_any($order->lines, static fn (OrderLineRow $line): bool => $line->kind === SellableKind::Physical)) {
            $this->stock->release(new ReleaseReservation(self::demand($order)));
        }

        // Whether usage comes back is the policy of the promotions (`restore_on`, D13), not of sales.
        $this->usage->restore(new RestorePromotionUsage(
            self::demand($order),
            $reason === CancelReason::Expired ? RestoreReason::Expired : RestoreReason::Cancelled,
        ));

        $this->status->refresh($order, $reason, $actor);
    }

    private static function demand(OrderRow $order): string
    {
        return 'order:'.$order->id->value;
    }
}
