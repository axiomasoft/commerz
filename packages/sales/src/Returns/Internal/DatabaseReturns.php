<?php

declare(strict_types=1);

namespace Commerz\Sales\Returns\Internal;

use Commerz\Operations\Contracts\CommittedEvents;
use Commerz\Operations\Contracts\OperationReceipts;
use Commerz\Operations\Contracts\Transactions;
use Commerz\Operations\Fingerprint;
use Commerz\Outbox\Contracts\Outbox;
use Commerz\Outbox\Data\IntegrationEvent;
use Commerz\Pagination\Page;
use Commerz\Ports\Sellables\Enums\SellableKind;
use Commerz\Ports\Stock\Contracts\StockReturns;
use Commerz\Ports\Stock\Data\RestockLine;
use Commerz\Ports\Stock\Data\RestockStock;
use Commerz\Sales\Common\Data\LineQuantity;
use Commerz\Sales\Common\Exceptions\LineNotFound;
use Commerz\Sales\Common\Internal\OrderJournal;
use Commerz\Sales\Common\Internal\OrderLedger;
use Commerz\Sales\Common\Internal\OrderLocks;
use Commerz\Sales\Common\Internal\OrderRow;
use Commerz\Sales\Common\Internal\OrderStatus;
use Commerz\Sales\Orders\Enums\OrderState;
use Commerz\Sales\Returns\Contracts\Returns;
use Commerz\Sales\Returns\Data\CancelReturn;
use Commerz\Sales\Returns\Data\ListReturns;
use Commerz\Sales\Returns\Data\ReceiveReturn;
use Commerz\Sales\Returns\Data\RequestReturn;
use Commerz\Sales\Returns\Data\ReturnId;
use Commerz\Sales\Returns\Data\ReturnLineView;
use Commerz\Sales\Returns\Data\ReturnView;
use Commerz\Sales\Returns\Enums\ReturnStatus;
use Commerz\Sales\Returns\Events\ReturnReceived;
use Commerz\Sales\Returns\Exceptions\RestockExceedsReceived;
use Commerz\Sales\Returns\Exceptions\ReturnExceedsFulfilled;
use Commerz\Sales\Returns\Exceptions\ReturnNotOpen;
use LogicException;
use Packman\Attributes\Internal;

/**
 * Returns of orders (sales-design#returns-refunds). Every change runs under the lock of the order, then of the return
 * (sales-design#locks). Receiving counts the units on the ledger and restocks exactly the listed physical units in one
 * transaction; nothing else ever puts stock back (REF-01).
 */
#[Internal]
final readonly class DatabaseReturns implements Returns
{
    private const string SCOPE = 'sales.request_return';

    public function __construct(
        private Transactions $transactions,
        private OperationReceipts $receipts,
        private OrderLocks $locks,
        private OrderLedger $ledger,
        private OrderStatus $status,
        private OrderJournal $journal,
        private ReturnRows $rows,
        private StockReturns $stock,
        private Outbox $outbox,
        private CommittedEvents $events,
    ) {}

    public function request(RequestReturn $command): ReturnView
    {
        return $this->transactions->run(function () use ($command): ReturnView {
            if ($command->idempotencyKey === null) {
                return $this->requestLocked($command);
            }

            $lines = array_map(static fn (LineQuantity $line): array => [$line->lineId->value, $line->quantity], self::byLine($command->lines));

            return $this->receipts->once(
                self::SCOPE,
                $command->idempotencyKey,
                Fingerprint::of(['order' => $command->order->value, 'lines' => $lines, 'reason' => $command->reason]),
                fn (): ReturnView => $this->requestLocked($command),
                static fn (ReturnView $view): array => ['return_id' => $view->id->value],
                fn (array $stored): ReturnView => $this->rows->get(is_string($stored['return_id'] ?? null) ? $stored['return_id'] : throw new LogicException('A return receipt holds no return id.')),
            );
        });
    }

    public function receive(ReceiveReturn $command): ReturnView
    {
        $orderId = $this->rows->orderOf($command->return);

        return $this->transactions->run(function () use ($command, $orderId): ReturnView {
            $order = $this->locks->lock($orderId);
            $current = $this->rows->lock($command->return);

            if ($current->status === ReturnStatus::Received) {
                return $current;
            }

            if ($current->status === ReturnStatus::Cancelled) {
                throw new ReturnNotOpen($current->id->value, $current->status->value);
            }

            $received = [];

            foreach ($current->lines as $line) {
                $received[$line->lineId->value] = $line->quantity;
            }

            $restock = [];

            foreach (self::byLine($command->restock) as $line) {
                $held = $received[$line->lineId->value] ?? throw new LineNotFound($line->lineId->value);

                if ($line->quantity > $held) {
                    throw new RestockExceedsReceived($line->lineId->value, $line->quantity, $held);
                }

                $restock[$line->lineId->value] = $line->quantity;
            }

            foreach ($current->lines as $line) {
                $this->ledger->returnReceived($order->id, new LineQuantity($line->lineId, $line->quantity));
            }

            // Only physical goods go back on hand; a service or a digital line is received without touching the stock.
            $restocked = [];
            $lines = [];

            foreach ($restock as $lineId => $quantity) {
                $stored = $order->line($lineId) ?? throw new LineNotFound($lineId);

                if ($stored->kind === SellableKind::Physical) {
                    $restocked[$lineId] = $quantity;
                    $lines[] = new RestockLine($stored->sellable, $quantity);
                }
            }

            $this->rows->markReceived($current->id, $command->locationCode, $restocked);

            if ($lines !== []) {
                $this->stock->restock(new RestockStock('return:'.$current->id->value, $this->rows->channelOf($order->id), $lines, $command->locationCode));
            }

            $this->journal->append($order->id, 'return_received', $command->actor, [
                'return_id' => $current->id->value,
                'location_code' => $command->locationCode,
                'restocked_quantity' => array_sum($restocked),
            ]);
            $this->status->refresh($order, actor: $command->actor);
            $this->announceReceived($order, $current, array_sum($restocked));

            return $this->rows->get($current->id);
        });
    }

    public function cancel(CancelReturn $command): ReturnView
    {
        $orderId = $this->rows->orderOf($command->return);

        return $this->transactions->run(function () use ($command, $orderId): ReturnView {
            $order = $this->locks->lock($orderId);
            $current = $this->rows->lock($command->return);

            if ($current->status === ReturnStatus::Cancelled) {
                return $current;
            }

            if ($current->status === ReturnStatus::Received) {
                throw new ReturnNotOpen($current->id->value, $current->status->value);
            }

            $this->rows->markCancelled($current->id);
            $this->journal->append($order->id, 'return_cancelled', $command->actor, ['return_id' => $current->id->value]);

            return $this->rows->get($current->id);
        });
    }

    public function find(ReturnId $id): ReturnView
    {
        return $this->rows->get($id);
    }

    public function list(ListReturns $query): Page
    {
        return $this->rows->page($query);
    }

    /** Under the lock of the order: what can still be returned of each line is what was fulfilled, less what came back and what open returns hold. */
    private function requestLocked(RequestReturn $command): ReturnView
    {
        $order = $this->locks->lock($command->order);
        $lines = self::byLine($command->lines);
        $open = $this->rows->openQuantities($order->id);

        foreach ($lines as $line) {
            $stored = $order->line($line->lineId->value) ?? throw new LineNotFound($line->lineId->value);
            $available = $order->state === OrderState::Cancelled
                ? 0
                : max(0, $stored->fulfilled - $stored->returned - ($open[$line->lineId->value] ?? 0));

            if ($line->quantity > $available) {
                throw new ReturnExceedsFulfilled($line->lineId->value, $line->quantity, $available);
            }
        }

        $id = ReturnId::generate();
        $this->rows->insert($id, $order->id, $command->reason, $lines);
        $this->journal->append($order->id, 'return_requested', $command->actor, ['return_id' => $id->value, 'reason' => $command->reason]);

        return $this->rows->get($id);
    }

    private function announceReceived(OrderRow $order, ReturnView $return, int $restocked): void
    {
        $this->outbox->record(IntegrationEvent::of('sales.return_received', 'order:'.$order->id->value, [
            'order_id' => $order->id->value,
            'number' => $order->number,
            'return_id' => $return->id->value,
            'quantity' => array_sum(array_map(static fn (ReturnLineView $line): int => $line->quantity, $return->lines)),
            'restocked_quantity' => $restocked,
        ]));
        $this->events->dispatch(new ReturnReceived($return->id, $order->id));
    }

    /**
     * The lines in id order, the order every writer of several lines takes them in.
     *
     * @param  list<LineQuantity>  $lines
     * @return list<LineQuantity>
     */
    private static function byLine(array $lines): array
    {
        usort($lines, static fn (LineQuantity $a, LineQuantity $b): int => strcmp($a->lineId->value, $b->lineId->value));

        return $lines;
    }
}
