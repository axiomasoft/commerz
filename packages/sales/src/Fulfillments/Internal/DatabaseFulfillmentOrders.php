<?php

declare(strict_types=1);

namespace Commerz\Sales\Fulfillments\Internal;

use Commerz\Operations\Contracts\CommittedEvents;
use Commerz\Operations\Contracts\OperationReceipts;
use Commerz\Operations\Contracts\Transactions;
use Commerz\Operations\Fingerprint;
use Commerz\Outbox\Contracts\Outbox;
use Commerz\Outbox\Data\IntegrationEvent;
use Commerz\Pagination\Page;
use Commerz\Sales\Common\Data\LineQuantity;
use Commerz\Sales\Common\Exceptions\LineNotFound;
use Commerz\Sales\Common\Exceptions\QuantityExceedsOutstanding;
use Commerz\Sales\Common\Internal\OrderJournal;
use Commerz\Sales\Common\Internal\OrderLocks;
use Commerz\Sales\Fulfillments\Contracts\FulfillmentOrders;
use Commerz\Sales\Fulfillments\Data\CreateFulfillmentOrder;
use Commerz\Sales\Fulfillments\Data\FulfillmentOrder;
use Commerz\Sales\Fulfillments\Data\FulfillmentOrderId;
use Commerz\Sales\Fulfillments\Data\ListFulfillmentOrders;
use Commerz\Sales\Fulfillments\Data\ReassignFulfillmentOrder;
use Commerz\Sales\Fulfillments\Data\TransitionFulfillmentOrder;
use Commerz\Sales\Fulfillments\Events\FulfillmentOrderCreated;
use Commerz\Sales\Fulfillments\Events\FulfillmentOrderReassigned;
use Commerz\Sales\Fulfillments\Exceptions\OrderNotFulfillable;
use Commerz\Sales\Orders\Data\OrderId;
use Commerz\Sales\Orders\Enums\OrderState;
use Commerz\Time\Contracts\Clock;
use Commerz\Time\PgTimestamp;
use LogicException;
use Packman\Attributes\Internal;

/**
 * Persists and transitions {@see FulfillmentOrder} warehouse assignments under the lock of the owning order.
 */
#[Internal]
final readonly class DatabaseFulfillmentOrders implements FulfillmentOrders
{
    private const string SCOPE = 'sales.create_fulfillment_order';

    public function __construct(
        private Transactions $transactions,
        private OperationReceipts $receipts,
        private OrderLocks $locks,
        private OrderJournal $journal,
        private FulfillmentOrderRows $rows,
        private Outbox $outbox,
        private CommittedEvents $events,
        private Clock $clock,
    ) {}

    public function create(CreateFulfillmentOrder $command): FulfillmentOrder
    {
        return $this->transactions->run(function () use ($command): FulfillmentOrder {
            if ($command->idempotencyKey === null) {
                return $this->createLocked($command);
            }

            $lines = array_map(
                static fn (LineQuantity $line): array => [$line->lineId->value, $line->quantity],
                self::byLine($command->lines),
            );

            return $this->receipts->once(
                self::SCOPE,
                $command->idempotencyKey,
                Fingerprint::of([
                    'order' => $command->order->value,
                    'location' => $command->locationCode,
                    'lines' => $lines,
                    'constraints' => $command->constraints->toArray(),
                ]),
                fn (): FulfillmentOrder => $this->createLocked($command),
                static fn (FulfillmentOrder $order): array => ['fulfillment_order_id' => $order->id->value],
                fn (array $stored): FulfillmentOrder => $this->rows->get(
                    is_string($stored['fulfillment_order_id'] ?? null)
                        ? $stored['fulfillment_order_id']
                        : throw new LogicException('A fulfillment order receipt holds no fulfillment order id.'),
                ),
            );
        });
    }

    public function reassign(ReassignFulfillmentOrder $command): FulfillmentOrder
    {
        $orderId = $this->rows->orderOf($command->fulfillmentOrder);

        return $this->transactions->run(function () use ($command, $orderId): FulfillmentOrder {
            $order = $this->locks->lock($orderId);
            $current = $this->rows->lock($command->fulfillmentOrder);

            if ($current->locationCode === $command->locationCode) {
                return $current;
            }

            $previousLocation = $current->locationCode;
            $updated = $current->reassign($command->locationCode, PgTimestamp::current($this->clock));
            $this->rows->save($updated);

            $this->journal->append($order->id, 'fulfillment_order_reassigned', $command->actor, [
                'fulfillment_order_id' => $updated->id->value,
                'previous_location_code' => $previousLocation,
                'location_code' => $updated->locationCode,
            ]);
            $this->outbox->record(IntegrationEvent::of('sales.fulfillment_order_reassigned', 'order:'.$order->id->value, [
                'order_id' => $order->id->value,
                'fulfillment_order_id' => $updated->id->value,
                'previous_location_code' => $previousLocation,
                'location_code' => $updated->locationCode,
            ]));
            $this->events->dispatch(new FulfillmentOrderReassigned($updated->id, $order->id, $previousLocation, $updated->locationCode));

            return $this->rows->get($updated->id);
        });
    }

    public function transition(TransitionFulfillmentOrder $command): FulfillmentOrder
    {
        $orderId = $this->rows->orderOf($command->fulfillmentOrder);

        return $this->transactions->run(function () use ($command, $orderId): FulfillmentOrder {
            $order = $this->locks->lock($orderId);
            $current = $this->rows->lock($command->fulfillmentOrder);

            if ($current->status === $command->status) {
                return $current;
            }

            $updated = $current->transitionTo($command->status, PgTimestamp::current($this->clock), $command->fulfillment);
            $this->rows->save($updated);

            $this->journal->append($order->id, 'fulfillment_order_status_changed', $command->actor, [
                'fulfillment_order_id' => $updated->id->value,
                'from' => $current->status->value,
                'to' => $updated->status->value,
                'fulfillment_id' => $updated->fulfillment?->value,
            ]);

            return $this->rows->get($updated->id);
        });
    }

    public function find(FulfillmentOrderId $id): FulfillmentOrder
    {
        return $this->rows->get($id);
    }

    public function list(ListFulfillmentOrders $query): Page
    {
        return $this->rows->page($query);
    }

    public function forOrder(OrderId $order): array
    {
        return $this->rows->forOrder($order);
    }

    private function createLocked(CreateFulfillmentOrder $command): FulfillmentOrder
    {
        $order = $this->locks->lock($command->order);

        if ($order->state !== OrderState::Placed) {
            throw new OrderNotFulfillable($order->id->value, $order->state->value);
        }

        $lines = self::byLine($command->lines);
        $alreadyAssigned = $this->rows->assignedQuantities($order->id);

        foreach ($lines as $line) {
            $stored = $order->line($line->lineId->value) ?? throw new LineNotFound($line->lineId->value);
            $available = max(0, $stored->quantity - $stored->cancelled - ($alreadyAssigned[$line->lineId->value] ?? 0));

            if ($line->quantity > $available) {
                throw new QuantityExceedsOutstanding($line->lineId->value, $line->quantity, $available);
            }
        }

        $aggregate = FulfillmentOrder::assign(
            order: $order->id,
            locationCode: $command->locationCode,
            lines: $lines,
            constraints: $command->constraints,
            assignedAt: PgTimestamp::current($this->clock),
        );

        $this->rows->insert($aggregate);
        $this->journal->append($order->id, 'fulfillment_order_created', $command->actor, [
            'fulfillment_order_id' => $aggregate->id->value,
            'location_code' => $aggregate->locationCode,
        ]);
        $this->outbox->record(IntegrationEvent::of('sales.fulfillment_order_created', 'order:'.$order->id->value, [
            'order_id' => $order->id->value,
            'number' => $order->number,
            'fulfillment_order_id' => $aggregate->id->value,
            'location_code' => $aggregate->locationCode,
        ]));
        $this->events->dispatch(new FulfillmentOrderCreated($aggregate->id, $order->id, $aggregate->locationCode));

        return $this->rows->get($aggregate->id);
    }

    /**
     * @param  non-empty-list<LineQuantity>  $lines
     * @return non-empty-list<LineQuantity>
     */
    private static function byLine(array $lines): array
    {
        usort($lines, static fn (LineQuantity $a, LineQuantity $b): int => strcmp($a->lineId->value, $b->lineId->value));

        return $lines;
    }
}
