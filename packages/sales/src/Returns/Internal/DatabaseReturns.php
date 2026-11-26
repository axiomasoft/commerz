<?php

declare(strict_types=1);

namespace Commerz\Sales\Returns\Internal;

use Commerz\Identity\SellableRef;
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
use Commerz\Ports\Stock\Data\WriteOffLine;
use Commerz\Ports\Stock\Data\WriteOffStock;
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
use Commerz\Sales\Returns\Enums\ReturnDisposition;
use Commerz\Sales\Returns\Enums\ReturnReason;
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
            $payload = ['order' => $command->order->value, 'lines' => $lines, 'reason' => $command->reason];

            if ($command->returnReason !== null) {
                $payload['return_reason'] = $command->returnReason->value;
            }

            return $this->receipts->once(
                self::SCOPE,
                $command->idempotencyKey,
                Fingerprint::of($payload),
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

            /** @var array<string, int> $restock */
            $restock = [];
            /** @var array<string, int> $writeOff */
            $writeOff = [];
            /** @var array<string, ReturnDisposition> $lineDispositions */
            $lineDispositions = [];
            /** @var array<string, ReturnReason> $lineReasons */
            $lineReasons = [];

            if ($command->dispositions !== []) {
                $dispositions = $command->dispositions;
                usort($dispositions, static fn ($a, $b): int => strcmp($a->lineId->value, $b->lineId->value));

                foreach ($dispositions as $item) {
                    $lineId = $item->lineId->value;
                    $held = $received[$lineId] ?? throw new LineNotFound($lineId);
                    $nextTotal = ($restock[$lineId] ?? 0) + ($writeOff[$lineId] ?? 0) + $item->quantity;

                    if ($nextTotal > $held) {
                        throw new RestockExceedsReceived($lineId, $nextTotal, $held);
                    }

                    if ($item->disposition === ReturnDisposition::Restock) {
                        $restock[$lineId] = ($restock[$lineId] ?? 0) + $item->quantity;
                    } else {
                        $writeOff[$lineId] = ($writeOff[$lineId] ?? 0) + $item->quantity;
                    }

                    $lineDispositions[$lineId] = isset($restock[$lineId], $writeOff[$lineId])
                        ? ($restock[$lineId] >= $writeOff[$lineId] ? ReturnDisposition::Restock : ReturnDisposition::DamagedWriteOff)
                        : $item->disposition;

                    if ($item->reason !== null) {
                        $lineReasons[$lineId] = $item->reason;
                    }
                }
            } elseif ($command->restock !== []) {
                foreach (self::byLine($command->restock) as $line) {
                    $held = $received[$line->lineId->value] ?? throw new LineNotFound($line->lineId->value);

                    if ($line->quantity > $held) {
                        throw new RestockExceedsReceived($line->lineId->value, $line->quantity, $held);
                    }

                    $restock[$line->lineId->value] = $line->quantity;
                    $lineDispositions[$line->lineId->value] = ReturnDisposition::Restock;
                }
            } elseif ($command->disposition === ReturnDisposition::Restock) {
                foreach ($current->lines as $line) {
                    $restock[$line->lineId->value] = $line->quantity;
                    $lineDispositions[$line->lineId->value] = ReturnDisposition::Restock;
                }
            } elseif ($command->disposition === ReturnDisposition::DamagedWriteOff) {
                foreach ($current->lines as $line) {
                    $writeOff[$line->lineId->value] = $line->quantity;
                    $lineDispositions[$line->lineId->value] = ReturnDisposition::DamagedWriteOff;
                }
            }

            $effectiveReason = $command->reason ?? $current->returnReason;

            if ($effectiveReason !== null) {
                foreach ($current->lines as $line) {
                    $lineReasons[$line->lineId->value] ??= $effectiveReason;
                }
            }

            foreach ($current->lines as $line) {
                $this->ledger->returnReceived($order->id, new LineQuantity($line->lineId, $line->quantity));
            }

            // Only physical goods touch stock: Restock goes back on hand; DamagedWriteOff journals write_off without increasing on_hand (INV-INV-002).
            $restocked = [];
            $restockBySellable = [];
            $restockRefs = [];

            foreach ($restock as $lineId => $quantity) {
                $stored = $order->line($lineId) ?? throw new LineNotFound($lineId);

                if ($stored->kind === SellableKind::Physical) {
                    $restocked[$lineId] = $quantity;
                    $refKey = (string) $stored->sellable;
                    $restockBySellable[$refKey] = ($restockBySellable[$refKey] ?? 0) + $quantity;
                    $restockRefs[$refKey] = $stored->sellable;
                }
            }

            $writtenOff = [];
            $writeOffBySellable = [];
            $writeOffRefs = [];

            foreach ($writeOff as $lineId => $quantity) {
                $stored = $order->line($lineId) ?? throw new LineNotFound($lineId);

                if ($stored->kind === SellableKind::Physical) {
                    $writtenOff[$lineId] = $quantity;
                    $refKey = (string) $stored->sellable;
                    $writeOffBySellable[$refKey] = ($writeOffBySellable[$refKey] ?? 0) + $quantity;
                    $writeOffRefs[$refKey] = $stored->sellable;
                }
            }

            $headerDisposition = $command->disposition;
            if ($headerDisposition === null) {
                if ($restocked !== [] && $writtenOff === []) {
                    $headerDisposition = ReturnDisposition::Restock;
                } elseif ($writtenOff !== [] && $restocked === []) {
                    $headerDisposition = ReturnDisposition::DamagedWriteOff;
                }
            }

            $this->rows->markReceived(
                $current->id,
                $command->locationCode,
                $restocked,
                $writtenOff,
                $headerDisposition,
                $command->reason,
                $lineDispositions,
                $lineReasons,
            );

            $channel = ($restockBySellable !== [] || $writeOffBySellable !== []) ? $this->rows->channelOf($order->id) : null;

            if ($channel !== null && $restockBySellable !== []) {
                $restockLines = array_map(
                    static fn (SellableRef $sellable, int $qty): RestockLine => new RestockLine($sellable, $qty),
                    array_values($restockRefs),
                    array_values($restockBySellable),
                );
                $this->stock->restock(new RestockStock('return:'.$current->id->value, $channel, $restockLines, $command->locationCode));
            }

            if ($channel !== null && $writeOffBySellable !== []) {
                $writeOffLines = array_map(
                    static fn (SellableRef $sellable, int $qty): WriteOffLine => new WriteOffLine($sellable, $qty),
                    array_values($writeOffRefs),
                    array_values($writeOffBySellable),
                );
                $reasonText = $effectiveReason !== null
                    ? $effectiveReason->value
                    : ($current->reason ?? ReturnDisposition::DamagedWriteOff->value);
                $this->stock->writeOff(new WriteOffStock(
                    'return:'.$current->id->value.':write_off',
                    $channel,
                    $writeOffLines,
                    $command->locationCode,
                    $reasonText,
                    $command->actor,
                ));
            }

            $this->journal->append($order->id, 'return_received', $command->actor, [
                'return_id' => $current->id->value,
                'location_code' => $command->locationCode,
                'disposition' => $headerDisposition?->value,
                'restocked_quantity' => array_sum($restocked),
                'written_off_quantity' => array_sum($writtenOff),
            ]);
            $this->status->refresh($order, actor: $command->actor);
            $this->announceReceived($order, $current, array_sum($restocked), array_sum($writtenOff), $headerDisposition);

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
        $this->rows->insert($id, $order->id, $command->reason, $lines, $command->returnReason);
        $this->journal->append($order->id, 'return_requested', $command->actor, [
            'return_id' => $id->value,
            'reason' => $command->reason,
            'reason_code' => $command->returnReason?->value,
        ]);

        return $this->rows->get($id);
    }

    private function announceReceived(
        OrderRow $order,
        ReturnView $return,
        int $restocked,
        int $writtenOff = 0,
        ?ReturnDisposition $disposition = null,
    ): void {
        $this->outbox->record(IntegrationEvent::of('sales.return_received', 'order:'.$order->id->value, [
            'order_id' => $order->id->value,
            'number' => $order->number,
            'return_id' => $return->id->value,
            'quantity' => array_sum(array_map(static fn (ReturnLineView $line): int => $line->quantity, $return->lines)),
            'restocked_quantity' => $restocked,
            'written_off_quantity' => $writtenOff,
            'disposition' => $disposition?->value,
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
