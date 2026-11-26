<?php

declare(strict_types=1);

namespace Commerz\Sales\Fulfillments\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Ports\Delivery\Data\FulfillmentConstraints;
use Commerz\Ports\Stock\Data\StockLines;
use Commerz\Sales\Common\Data\LineQuantity;
use Commerz\Sales\Fulfillments\Enums\FulfillmentOrderStatus;
use Commerz\Sales\Fulfillments\Exceptions\InvalidFulfillmentOrderState;
use Commerz\Sales\Orders\Data\OrderId;
use Commerz\Sales\Orders\Data\OrderView;
use DateTimeImmutable;
use Packman\Attributes\Api;

/**
 * Intermediate warehouse assignment aggregate between a placed {@see OrderView}
 * and physical {@see FulfillmentView} shipments. Owns its assigned location, line quantities, fulfillment
 * constraints, and lifecycle transitions.
 */
#[Api(since: '0.1')]
final readonly class FulfillmentOrder
{
    /**
     * @param  non-empty-list<LineQuantity>  $lines
     */
    public function __construct(
        public FulfillmentOrderId $id,
        public OrderId $order,
        public string $locationCode,
        public FulfillmentOrderStatus $status,
        public array $lines,
        public FulfillmentConstraints $constraints,
        public ?FulfillmentId $fulfillment,
        public DateTimeImmutable $assignedAt,
        public ?DateTimeImmutable $fulfilledAt,
        public ?DateTimeImmutable $cancelledAt,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
        if ($locationCode === '') {
            throw new InvalidCommand('locationCode', 'must not be empty', 'sales.invalid_fulfillment');
        }

        StockLines::assertLocation($locationCode);

        $seen = [];

        foreach ($lines as $line) {
            if (isset($seen[$line->lineId->value])) {
                throw new InvalidCommand('lines', 'must name each line once', 'sales.invalid_lines');
            }

            $seen[$line->lineId->value] = true;
        }
    }

    /**
     * Creates a new open warehouse assignment aggregate for an order.
     *
     * @param  non-empty-list<LineQuantity>  $lines
     */
    public static function assign(
        OrderId $order,
        string $locationCode,
        array $lines,
        FulfillmentConstraints $constraints,
        DateTimeImmutable $assignedAt,
        ?FulfillmentOrderId $id = null,
    ): self {
        return new self(
            id: $id ?? FulfillmentOrderId::generate(),
            order: $order,
            locationCode: $locationCode,
            status: FulfillmentOrderStatus::Open,
            lines: $lines,
            constraints: $constraints,
            fulfillment: null,
            assignedAt: $assignedAt,
            fulfilledAt: null,
            cancelledAt: null,
            createdAt: $assignedAt,
            updatedAt: $assignedAt,
        );
    }

    /** Reassigns this fulfillment order to another warehouse before work starts. */
    public function reassign(string $locationCode, DateTimeImmutable $at): self
    {
        if (! $this->status->allowsReassignment()) {
            throw new InvalidFulfillmentOrderState($this->id->value, $this->status->value, 'reassign');
        }

        if ($locationCode === '') {
            throw new InvalidCommand('locationCode', 'must not be empty', 'sales.invalid_fulfillment');
        }

        StockLines::assertLocation($locationCode);

        return new self(
            id: $this->id,
            order: $this->order,
            locationCode: $locationCode,
            status: $this->status,
            lines: $this->lines,
            constraints: $this->constraints,
            fulfillment: $this->fulfillment,
            assignedAt: $at,
            fulfilledAt: $this->fulfilledAt,
            cancelledAt: $this->cancelledAt,
            createdAt: $this->createdAt,
            updatedAt: $at,
        );
    }

    /** Transitions this fulfillment order to `$next`, enforcing the aggregate state machine. */
    public function transitionTo(
        FulfillmentOrderStatus $next,
        DateTimeImmutable $at,
        ?FulfillmentId $fulfillment = null,
    ): self {
        if ($this->status === $next) {
            return $this;
        }

        if (! $this->status->canTransitionTo($next)) {
            throw new InvalidFulfillmentOrderState($this->id->value, $this->status->value, $next->value);
        }

        return new self(
            id: $this->id,
            order: $this->order,
            locationCode: $this->locationCode,
            status: $next,
            lines: $this->lines,
            constraints: $this->constraints,
            fulfillment: $next === FulfillmentOrderStatus::Fulfilled ? ($fulfillment ?? $this->fulfillment) : $this->fulfillment,
            assignedAt: $this->assignedAt,
            fulfilledAt: $next === FulfillmentOrderStatus::Fulfilled ? $at : $this->fulfilledAt,
            cancelledAt: $next === FulfillmentOrderStatus::Cancelled ? $at : $this->cancelledAt,
            createdAt: $this->createdAt,
            updatedAt: $at,
        );
    }

    public function start(DateTimeImmutable $at): self
    {
        return $this->transitionTo(FulfillmentOrderStatus::InProgress, $at);
    }

    public function hold(DateTimeImmutable $at): self
    {
        return $this->transitionTo(FulfillmentOrderStatus::OnHold, $at);
    }

    public function releaseHold(DateTimeImmutable $at): self
    {
        return $this->transitionTo(FulfillmentOrderStatus::Open, $at);
    }

    public function markFulfilled(DateTimeImmutable $at, ?FulfillmentId $fulfillment = null): self
    {
        return $this->transitionTo(FulfillmentOrderStatus::Fulfilled, $at, $fulfillment);
    }

    public function cancel(DateTimeImmutable $at): self
    {
        return $this->transitionTo(FulfillmentOrderStatus::Cancelled, $at);
    }

    public function totalQuantity(): int
    {
        return array_sum(array_map(static fn (LineQuantity $line): int => $line->quantity, $this->lines));
    }
}
