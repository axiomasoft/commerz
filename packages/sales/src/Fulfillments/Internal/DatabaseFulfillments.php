<?php

declare(strict_types=1);

namespace Commerz\Sales\Fulfillments\Internal;

use Commerz\Identity\ActorRef;
use Commerz\Operations\Contracts\CommittedEvents;
use Commerz\Operations\Contracts\OperationReceipts;
use Commerz\Operations\Contracts\Transactions;
use Commerz\Operations\Fingerprint;
use Commerz\Outbox\Contracts\Outbox;
use Commerz\Outbox\Data\IntegrationEvent;
use Commerz\Pagination\Page;
use Commerz\Ports\Sellables\Enums\SellableKind;
use Commerz\Ports\Stock\Contracts\StockReservations;
use Commerz\Ports\Stock\Data\ConsumeReservation;
use Commerz\Sales\Common\Data\LineQuantity;
use Commerz\Sales\Common\Exceptions\LineNotFound;
use Commerz\Sales\Common\Exceptions\OperationInsideTransaction;
use Commerz\Sales\Common\Internal\OrderJournal;
use Commerz\Sales\Common\Internal\OrderLedger;
use Commerz\Sales\Common\Internal\OrderLocks;
use Commerz\Sales\Common\Internal\OrderRow;
use Commerz\Sales\Common\Internal\OrderStatus;
use Commerz\Sales\Fulfillments\Contracts\Fulfillments;
use Commerz\Sales\Fulfillments\Data\BookFulfillment;
use Commerz\Sales\Fulfillments\Data\CreateFulfillment;
use Commerz\Sales\Fulfillments\Data\FulfillmentId;
use Commerz\Sales\Fulfillments\Data\FulfillmentView;
use Commerz\Sales\Fulfillments\Data\ListFulfillments;
use Commerz\Sales\Fulfillments\Data\MarkFulfillmentDelivered;
use Commerz\Sales\Fulfillments\Data\ShipFulfillment;
use Commerz\Sales\Fulfillments\Enums\FulfillmentStatus;
use Commerz\Sales\Fulfillments\Events\FulfillmentCreated;
use Commerz\Sales\Fulfillments\Events\FulfillmentShipped;
use Commerz\Sales\Fulfillments\Exceptions\FulfillmentAlreadyShipped;
use Commerz\Sales\Fulfillments\Exceptions\FulfillmentNotShipped;
use Commerz\Sales\Fulfillments\Exceptions\OrderNotFulfillable;
use Commerz\Sales\Orders\Enums\OrderState;
use LogicException;

/**
 * Fulfillments of orders (sales-design#fulfillment). Every change runs under the lock of the order, then of the
 * document (sales-design#locks); a fulfillment moves the ledger, consumes stock and refreshes the order in one
 * transaction. The carrier is asked only by `book`, outside any transaction.
 */
final readonly class DatabaseFulfillments implements Fulfillments
{
    private const string SCOPE = 'sales.create_fulfillment';

    public function __construct(
        private Transactions $transactions,
        private OperationReceipts $receipts,
        private OrderLocks $locks,
        private OrderLedger $ledger,
        private OrderStatus $status,
        private OrderJournal $journal,
        private FulfillmentRows $rows,
        private FulfillmentBooking $booking,
        private StockReservations $stock,
        private Outbox $outbox,
        private CommittedEvents $events,
    ) {}

    public function create(CreateFulfillment $command): FulfillmentView
    {
        return $this->transactions->run(function () use ($command): FulfillmentView {
            if ($command->idempotencyKey === null) {
                return $this->createLocked($command);
            }

            $lines = array_map(static fn (LineQuantity $line): array => [$line->lineId->value, $line->quantity], self::byLine($command->lines));

            return $this->receipts->once(
                self::SCOPE,
                $command->idempotencyKey,
                Fingerprint::of(['order' => $command->order->value, 'lines' => $lines, 'location' => $command->locationCode]),
                fn (): FulfillmentView => $this->createLocked($command),
                static fn (FulfillmentView $view): array => ['fulfillment_id' => $view->id->value],
                fn (array $stored): FulfillmentView => $this->rows->get(is_string($stored['fulfillment_id'] ?? null) ? $stored['fulfillment_id'] : throw new LogicException('A fulfillment receipt holds no fulfillment id.')),
            );
        });
    }

    public function ship(ShipFulfillment $command): FulfillmentView
    {
        return $this->shipped($command->fulfillment, $command->carrier, $command->tracking, null, null, $command->actor);
    }

    public function book(BookFulfillment $command): FulfillmentView
    {
        if ($this->transactions->inTransaction()) {
            throw new OperationInsideTransaction('sales.fulfillments.book');
        }

        // Read without a lock: a shipped fulfillment has nothing to book, and the carrier call must hold no lock.
        $fulfillment = $this->rows->get($command->fulfillment);

        if ($fulfillment->status !== FulfillmentStatus::Created) {
            return $fulfillment;
        }

        $request = $this->booking->request($fulfillment, $command);
        $booked = $this->booking->send($request);

        return $this->shipped($fulfillment->id, $booked->carrier, $booked->tracking, $booked->labelRef, $request->methodCode, $command->actor);
    }

    public function markDelivered(MarkFulfillmentDelivered $command): FulfillmentView
    {
        $order = $this->rows->orderOf($command->fulfillment);

        return $this->transactions->run(function () use ($command, $order): FulfillmentView {
            $row = $this->locks->lock($order);
            $current = $this->rows->lock($command->fulfillment);

            if ($current->status === FulfillmentStatus::Delivered) {
                return $current;
            }

            if ($current->status === FulfillmentStatus::Created) {
                throw new FulfillmentNotShipped($current->id->value);
            }

            $this->rows->markDelivered($current->id);
            $this->journal->append($row->id, 'fulfillment_delivered', $command->actor, ['fulfillment_id' => $current->id->value]);
            $this->status->refresh($row, actor: $command->actor);

            return $this->rows->get($current->id);
        });
    }

    public function find(FulfillmentId $id): FulfillmentView
    {
        return $this->rows->get($id);
    }

    public function list(ListFulfillments $query): Page
    {
        return $this->rows->page($query);
    }

    /**
     * Under the lock of the order: the document, the ledger of its lines, then the stock of the physical ones, all in
     * line order; any refusal rolls the whole fulfillment back.
     */
    private function createLocked(CreateFulfillment $command): FulfillmentView
    {
        $order = $this->locks->lock($command->order);

        if ($order->state !== OrderState::Placed) {
            throw new OrderNotFulfillable($order->id->value, $order->state->value);
        }

        $id = FulfillmentId::generate();
        $lines = self::byLine($command->lines);
        $stored = [];

        foreach ($lines as $line) {
            $stored[] = $order->line($line->lineId->value) ?? throw new LineNotFound($line->lineId->value);
        }

        $this->rows->insert($id, $order->id, $command->locationCode, $lines);

        foreach ($lines as $line) {
            $this->ledger->fulfill($order->id, $line);
        }

        // Only physical goods hold stock; a service or a digital line is fulfilled without touching it.
        foreach ($lines as $index => $line) {
            if ($stored[$index]->kind === SellableKind::Physical) {
                $this->stock->consume(new ConsumeReservation(
                    'order:'.$order->id->value,
                    $stored[$index]->sellable,
                    $line->quantity,
                    'fulfillment:'.$id->value,
                    $command->locationCode,
                ));
            }
        }

        $this->journal->append($order->id, 'fulfillment_created', $command->actor, [
            'fulfillment_id' => $id->value,
            'location_code' => $command->locationCode,
        ]);
        $this->status->refresh($order, actor: $command->actor);
        $this->events->dispatch(new FulfillmentCreated($id, $order->id));

        return $this->rows->get($id);
    }

    /**
     * Ships the fulfillment under the locks of its order and itself. A repeat with the same carrier and tracking is
     * the same shipment; another one never overwrites it.
     */
    private function shipped(FulfillmentId $id, ?string $carrier, ?string $tracking, ?string $labelRef, ?string $methodCode, ?ActorRef $actor): FulfillmentView
    {
        $orderId = $this->rows->orderOf($id);

        return $this->transactions->run(function () use ($id, $orderId, $carrier, $tracking, $labelRef, $methodCode, $actor): FulfillmentView {
            $order = $this->locks->lock($orderId);
            $current = $this->rows->lock($id);

            if ($current->status !== FulfillmentStatus::Created) {
                if ($current->carrier === $carrier && $current->tracking === $tracking) {
                    return $current;
                }

                throw new FulfillmentAlreadyShipped($id->value, $current->carrier, $current->tracking);
            }

            $this->rows->markShipped($id, $carrier, $tracking, $labelRef, $methodCode);
            $this->journal->append($order->id, 'fulfillment_shipped', $actor, [
                'fulfillment_id' => $id->value,
                'carrier' => $carrier,
                'tracking' => $tracking,
            ]);
            $this->announceShipped($order, $id, $carrier, $tracking);
            $this->status->refresh($order, actor: $actor);

            return $this->rows->get($id);
        });
    }

    private function announceShipped(OrderRow $order, FulfillmentId $id, ?string $carrier, ?string $tracking): void
    {
        $this->outbox->record(IntegrationEvent::of('sales.fulfillment_shipped', 'order:'.$order->id->value, [
            'order_id' => $order->id->value,
            'number' => $order->number,
            'fulfillment_id' => $id->value,
            'carrier' => $carrier,
            'tracking' => $tracking,
        ]));
        $this->events->dispatch(new FulfillmentShipped($id, $order->id, $tracking));
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
