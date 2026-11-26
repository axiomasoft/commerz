<?php

declare(strict_types=1);

namespace Commerz\Sales\OrderChanges\Internal;

use Commerz\Errors\InvalidCommand;
use Commerz\Identity\ActorRef;
use Commerz\Identity\IdempotencyKey;
use Commerz\Money\Money;
use Commerz\Operations\Contracts\CommittedEvents;
use Commerz\Operations\Contracts\Transactions;
use Commerz\Outbox\Contracts\Outbox;
use Commerz\Outbox\Data\IntegrationEvent;
use Commerz\Ports\Stock\Contracts\StockReservations;
use Commerz\Ports\Stock\Data\ReleaseReservation;
use Commerz\Ports\Stock\Data\ReserveLine;
use Commerz\Ports\Stock\Data\ReserveStock;
use Commerz\Sales\Common\Exceptions\OperationInsideTransaction;
use Commerz\Sales\Common\Internal\OrderJournal;
use Commerz\Sales\Common\Internal\OrderLocks;
use Commerz\Sales\Common\Internal\OrderRows;
use Commerz\Sales\Common\Internal\OrderStatus;
use Commerz\Sales\Common\Internal\OrderWriter;
use Commerz\Sales\OrderChanges\Contracts\OrderChangeCompensator;
use Commerz\Sales\OrderChanges\Contracts\OrderChanges;
use Commerz\Sales\OrderChanges\Data\AddOrderChangeAction;
use Commerz\Sales\OrderChanges\Data\CancelOrderChange;
use Commerz\Sales\OrderChanges\Data\CompensationOutcome;
use Commerz\Sales\OrderChanges\Data\CompensationPreference;
use Commerz\Sales\OrderChanges\Data\CreateOrderChange;
use Commerz\Sales\OrderChanges\Data\OrderChangeActionId;
use Commerz\Sales\OrderChanges\Data\OrderChangeConfirmation;
use Commerz\Sales\OrderChanges\Data\OrderChangeId;
use Commerz\Sales\OrderChanges\Data\OrderChangePreview;
use Commerz\Sales\OrderChanges\Data\OrderChangeView;
use Commerz\Sales\OrderChanges\Enums\CompensationStrategy;
use Commerz\Sales\OrderChanges\Enums\NegativeDeltaStrategy;
use Commerz\Sales\OrderChanges\Enums\OrderChangeStatus;
use Commerz\Sales\OrderChanges\Enums\PositiveDeltaStrategy;
use Commerz\Sales\OrderChanges\Events\OrderChangeCancelled;
use Commerz\Sales\OrderChanges\Events\OrderChangeConfirmed;
use Commerz\Sales\OrderChanges\Events\OrderChangeCreated;
use Commerz\Sales\OrderChanges\Exceptions\OrderChangeNotOpen;
use Commerz\Sales\OrderChanges\Exceptions\OrderChangeVersionConflict;
use Commerz\Sales\OrderChanges\Exceptions\OrderNotEditable;
use Commerz\Sales\Orders\Data\OrderId;
use Commerz\Sales\Orders\Data\OrderView;
use Commerz\Sales\Orders\Enums\OrderState;
use Commerz\Stores\Data\ChannelId;
use Commerz\Time\Contracts\Clock;
use Override;
use Packman\Attributes\Internal;

/**
 * Two-phase `OrderChange` lifecycle backed by PostgreSQL with strict OCC (`INV-OMS-001`), stock reservation delta
 * reconciliation, and compensating payment/refund/stored-value workflows.
 */
#[Internal]
final readonly class DatabaseOrderChanges implements OrderChanges
{
    public function __construct(
        private Transactions $transactions,
        private OrderLocks $locks,
        private OrderRows $orders,
        private OrderWriter $writer,
        private OrderStatus $status,
        private OrderJournal $journal,
        private OrderChangeRows $changes,
        private OrderChangeCalculator $calculator,
        private OrderChangeCompensator $compensator,
        private StockReservations $stock,
        private Outbox $outbox,
        private CommittedEvents $events,
        private Clock $clock,
    ) {}

    #[Override]
    public function create(CreateOrderChange $command): OrderChangeView
    {
        return $this->transactions->run(function () use ($command): OrderChangeView {
            $order = $this->locks->lock($command->order);

            if ($order->state !== OrderState::Placed) {
                throw new OrderNotEditable($order->id->value, $order->state->value);
            }

            $id = OrderChangeId::generate();
            $at = $this->clock->now();

            $this->changes->insert($id, $order->id, $order->version, $order->currency, $command->description, $command->actor, $at);
            $this->journal->append($order->id, 'order_change_created', $command->actor, [
                'order_change_id' => $id->value,
                'base_order_version' => $order->version,
            ]);
            $this->events->dispatch(new OrderChangeCreated($id, $order->id, $order->version));

            return $this->changes->get($id->value);
        });
    }

    #[Override]
    public function addAction(AddOrderChangeAction $command): OrderChangeView
    {
        return $this->transactions->run(function () use ($command): OrderChangeView {
            $change = $this->changes->lock($command->change->value);

            if ($change->status !== OrderChangeStatus::Draft) {
                throw new OrderChangeNotOpen($change->id->value, $change->status->value);
            }

            $nextPosition = count($change->actions) + 1;
            $actionId = OrderChangeActionId::generate();
            $this->changes->appendAction($change->id, $actionId, $nextPosition, $command->action, $this->clock->now());

            return $this->changes->get($change->id->value);
        });
    }

    #[Override]
    public function preview(OrderChangeId $change): OrderChangePreview
    {
        $changeView = $this->changes->get($change->value);
        $order = $this->orders->get($changeView->orderId->value);

        return $this->calculator->calculate($changeView->id, $order, $changeView->actions, $this->clock->now())->preview;
    }

    #[Override]
    public function confirm(
        OrderChangeId $change,
        int $expectedOrderVersion,
        ?CompensationPreference $compensation = null,
        ?IdempotencyKey $idempotencyKey = null,
        ?ActorRef $actor = null,
    ): OrderChangeConfirmation {
        if ($expectedOrderVersion < 1) {
            throw new InvalidCommand('expectedOrderVersion', 'must be at least 1', 'sales.invalid_order_change');
        }

        $changeView = $this->changes->get($change->value);

        if ($changeView->status !== OrderChangeStatus::Draft) {
            throw new OrderChangeNotOpen($changeView->id->value, $changeView->status->value);
        }

        if ($changeView->actions === []) {
            throw new InvalidCommand('actions', 'cannot confirm an order change with no actions', 'sales.invalid_order_change');
        }

        $orderBefore = $this->orders->get($changeView->orderId->value);

        if ($orderBefore->state !== OrderState::Placed) {
            throw new OrderNotEditable($orderBefore->id->value, $orderBefore->state->value);
        }

        if ($orderBefore->version !== $expectedOrderVersion) {
            throw new OrderChangeVersionConflict(
                $changeView->id->value,
                $orderBefore->id->value,
                $expectedOrderVersion,
                $orderBefore->version,
            );
        }

        $at = $this->clock->now();
        $calculation = $this->calculator->calculate($changeView->id, $orderBefore, $changeView->actions, $at);
        $preview = $calculation->preview;
        $preference = $compensation ?? new CompensationPreference;
        $amountDelta = $preview->amountDelta;

        $overpaidAfterMinor = max(
            0,
            $orderBefore->captured->amountMinor - $orderBefore->refunded->amountMinor - $preview->excerptAfter->amountDue->amountMinor,
        );

        $needsGatewayIncrease = $amountDelta->isPositive()
            && $preference->onIncrease !== PositiveDeltaStrategy::Deferred
            && ($preference->paymentGateway !== null || $orderBefore->captured->isPositive() || $orderBefore->authorized->isPositive());

        $needsGatewayDecrease = $amountDelta->isNegative()
            && $overpaidAfterMinor > 0
            && $preference->onDecrease === NegativeDeltaStrategy::PartialRefund;

        $needsWalletCredit = $amountDelta->isNegative()
            && $overpaidAfterMinor > 0
            && $preference->onDecrease === NegativeDeltaStrategy::WalletCredit;

        if (($needsGatewayIncrease || $needsGatewayDecrease) && $this->transactions->inTransaction()) {
            throw new OperationInsideTransaction('sales.order_changes.confirm');
        }

        if ($needsWalletCredit && $preference->storedValueAccountId === null && $orderBefore->customer === null) {
            throw new InvalidCommand('storedValueAccountId', 'wallet credit requires either storedValueAccountId or an order customer', 'sales.invalid_order_change');
        }

        $initialOutcome = new CompensationOutcome(CompensationStrategy::None, Money::zero($orderBefore->currency));

        /** @var array{order: OrderView, outcome: CompensationOutcome} $committed */
        $committed = $this->transactions->run(function () use (
            $changeView,
            $orderBefore,
            $expectedOrderVersion,
            $calculation,
            $preview,
            $preference,
            $amountDelta,
            $overpaidAfterMinor,
            $needsWalletCredit,
            $idempotencyKey,
            $actor,
            $at,
            $initialOutcome,
        ): array {
            $lockedOrder = $this->locks->lock($changeView->orderId);
            $lockedChange = $this->changes->lock($changeView->id->value);

            if ($lockedChange->status !== OrderChangeStatus::Draft) {
                throw new OrderChangeNotOpen($lockedChange->id->value, $lockedChange->status->value);
            }

            if ($lockedOrder->state !== OrderState::Placed) {
                throw new OrderNotEditable($lockedOrder->id->value, $lockedOrder->state->value);
            }

            if ($lockedOrder->version !== $expectedOrderVersion) {
                throw new OrderChangeVersionConflict(
                    $lockedChange->id->value,
                    $lockedOrder->id->value,
                    $expectedOrderVersion,
                    $lockedOrder->version,
                );
            }

            $applied = $this->writer->applyOrderChange(
                $lockedOrder->id,
                $expectedOrderVersion,
                $preview->excerptAfter,
                $preview->quoteFingerprint,
                $at,
            );

            if (! $applied) {
                throw new OrderChangeVersionConflict(
                    $lockedChange->id->value,
                    $lockedOrder->id->value,
                    $expectedOrderVersion,
                    $lockedOrder->version,
                );
            }

            $this->reconcileStock($lockedOrder->id, $orderBefore->channel, $calculation);

            $this->status->refresh($lockedOrder, actor: $actor, bumpVersion: false);
            $updatedOrder = $this->orders->get($lockedOrder->id->value);

            $outcome = $initialOutcome;

            if ($amountDelta->isPositive()) {
                $outcome = new CompensationOutcome(CompensationStrategy::Deferred, $amountDelta);
            } elseif ($amountDelta->isNegative() && $overpaidAfterMinor > 0) {
                $overpaid = Money::ofMinor($overpaidAfterMinor, $updatedOrder->currency);
                $outcome = $needsWalletCredit
                    ? $this->compensator->compensateDecrease($lockedChange->id, $updatedOrder, $overpaid, $preference, $idempotencyKey, $actor)
                    : new CompensationOutcome(CompensationStrategy::Deferred, $overpaid);
            }

            $newVersion = $expectedOrderVersion + 1;

            $this->changes->markConfirmed($lockedChange->id, $newVersion, $preview, $outcome, $actor, $at);

            $this->journal->append($lockedOrder->id, 'order_change_confirmed', $actor, [
                'order_change_id' => $lockedChange->id->value,
                'from_version' => $expectedOrderVersion,
                'to_version' => $newVersion,
                'amount_delta_minor' => $preview->amountDelta->amountMinor,
                'tax_delta_minor' => $preview->taxDelta->amountMinor,
                'currency' => $updatedOrder->currency->code,
            ]);

            $this->outbox->record(IntegrationEvent::of('sales.order_change_confirmed', 'order:'.$lockedOrder->id->value, [
                'order_change_id' => $lockedChange->id->value,
                'order_id' => $lockedOrder->id->value,
                'from_version' => $expectedOrderVersion,
                'to_version' => $newVersion,
                'amount_delta_minor' => $preview->amountDelta->amountMinor,
                'tax_delta_minor' => $preview->taxDelta->amountMinor,
                'currency' => $updatedOrder->currency->code,
                'compensation_strategy' => $outcome->strategy->value,
            ]));

            $this->events->dispatch(new OrderChangeConfirmed(
                $lockedChange->id,
                $lockedOrder->id,
                $expectedOrderVersion,
                $newVersion,
                $preview->amountDelta,
                $preview->taxDelta,
                $outcome->strategy,
            ));

            return ['order' => $updatedOrder, 'outcome' => $outcome];
        });

        $orderAfter = $committed['order'];
        $outcome = $committed['outcome'];

        // Gateway compensation runs OUTSIDE the database transaction (D33).
        if ($needsGatewayIncrease) {
            $outcome = $this->compensator->compensateIncrease($changeView->id, $orderAfter, $amountDelta, $preference, $idempotencyKey, $actor);
            $this->changes->recordCompensation($changeView->id, $outcome, $this->clock->now());
            $orderAfter = $this->orders->get($orderAfter->id->value);
        } elseif ($needsGatewayDecrease) {
            $overpaid = Money::ofMinor($overpaidAfterMinor, $orderAfter->currency);
            $outcome = $this->compensator->compensateDecrease($changeView->id, $orderAfter, $overpaid, $preference, $idempotencyKey, $actor);
            $this->changes->recordCompensation($changeView->id, $outcome, $this->clock->now());
            $orderAfter = $this->orders->get($orderAfter->id->value);
        }

        return new OrderChangeConfirmation(
            change: $this->changes->get($changeView->id->value),
            order: $orderAfter,
            preview: $preview,
            compensation: $outcome,
        );
    }

    #[Override]
    public function cancel(CancelOrderChange $command): OrderChangeView
    {
        return $this->transactions->run(function () use ($command): OrderChangeView {
            $change = $this->changes->lock($command->change->value);

            if ($change->status !== OrderChangeStatus::Draft) {
                throw new OrderChangeNotOpen($change->id->value, $change->status->value);
            }

            $this->changes->markCancelled($change->id, $this->clock->now());
            $this->journal->append($change->orderId, 'order_change_cancelled', $command->actor, [
                'order_change_id' => $change->id->value,
            ]);
            $this->events->dispatch(new OrderChangeCancelled($change->id, $change->orderId));

            return $this->changes->get($change->id->value);
        });
    }

    #[Override]
    public function find(OrderChangeId $id): OrderChangeView
    {
        return $this->changes->get($id->value);
    }

    #[Override]
    public function listForOrder(OrderId $order): array
    {
        return $this->changes->listForOrder($order);
    }

    private function reconcileStock(OrderId $orderId, ChannelId $channelId, OrderChangeCalculation $calculation): void
    {
        if ($calculation->stockDeltas === []) {
            return;
        }

        $demand = 'order:'.$orderId->value;
        $deltas = $calculation->stockDeltas;
        ksort($deltas);

        $reserveLines = [];
        $releases = [];

        foreach ($deltas as $key => $delta) {
            $sellable = $calculation->stockSellables[$key];

            if ($delta > 0) {
                $reserveLines[] = new ReserveLine($sellable, $delta);
            } else {
                $releases[] = new ReleaseReservation($demand, $sellable, -$delta);
            }
        }

        if ($reserveLines !== []) {
            $this->stock->reserve(new ReserveStock($demand, $channelId, $reserveLines));
        }

        foreach ($releases as $release) {
            $this->stock->release($release);
        }
    }
}
