<?php

declare(strict_types=1);

use Commerz\Commerz;
use Commerz\Errors\IdempotencyConflict;
use Commerz\Identity\IdempotencyKey;
use Commerz\Ports\Delivery\Data\FulfillmentConstraints;
use Commerz\Ports\Sellables\Contracts\Sellables;
use Commerz\Sales\Common\Data\LineQuantity;
use Commerz\Sales\Common\Exceptions\LineNotFound;
use Commerz\Sales\Common\Exceptions\QuantityExceedsOutstanding;
use Commerz\Sales\Fulfillments\Contracts\FulfillmentOrders;
use Commerz\Sales\Fulfillments\Data\CreateFulfillment;
use Commerz\Sales\Fulfillments\Data\CreateFulfillmentOrder;
use Commerz\Sales\Fulfillments\Data\FulfillmentOrderId;
use Commerz\Sales\Fulfillments\Data\ListFulfillmentOrders;
use Commerz\Sales\Fulfillments\Data\ReassignFulfillmentOrder;
use Commerz\Sales\Fulfillments\Data\TransitionFulfillmentOrder;
use Commerz\Sales\Fulfillments\Enums\FulfillmentOrderStatus;
use Commerz\Sales\Fulfillments\Events\FulfillmentOrderCreated;
use Commerz\Sales\Fulfillments\Events\FulfillmentOrderReassigned;
use Commerz\Sales\Fulfillments\Exceptions\FulfillmentOrderNotFound;
use Commerz\Sales\Fulfillments\Exceptions\InvalidFulfillmentOrderState;
use Commerz\Sales\Fulfillments\Exceptions\OrderNotFulfillable;
use Commerz\Sales\Orders\Data\CancelOrder;
use Commerz\Sales\Orders\Enums\CancelReason;
use Commerz\Testkit\Fakes\FakeSellableRouter;
use Commerz\Testkit\Fakes\FakeSellables;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Facade;

require_once __DIR__.'/Helpers.php';

uses(RefreshDatabase::class, SlsEnvironment::class);

beforeEach(function (): void {
    // Sellables only. Commerce::fake() would replace unlimited stock with the single-location fake,
    // and these orders are fulfilled from warehouse codes.
    $sellables = (new FakeSellables)->add(
        slsSnapshot('fake:cold', '100.00'),
        slsSnapshot('fake:dry', '40.00'),
    );
    app()->instance(Sellables::class, new FakeSellableRouter($sellables));
    Facade::clearResolvedInstance(Commerz::class);
    app()->forgetInstance(Commerz::class);
    slsConfig(['checkout' => ['require_delivery' => false]]);
    $this->order = slsPlace(slsCartWith(slsChannel(), ['fake:cold' => 3, 'fake:dry' => 2]));
});

it('creates, persists, reassigns, and fulfills a FulfillmentOrder with FulfillmentConstraints', function (): void {
    Event::fake([FulfillmentOrderCreated::class, FulfillmentOrderReassigned::class]);
    $orders = app(FulfillmentOrders::class);
    $constraints = new FulfillmentConstraints(coldChain: true, mustShipTogether: true, requiredLocationTags: ['pharma']);

    $created = $orders->create(new CreateFulfillmentOrder(
        order: $this->order->id,
        locationCode: 'wh-cold-1',
        lines: [
            new LineQuantity(slsLine($this->order, 'fake:cold'), 3),
            new LineQuantity(slsLine($this->order, 'fake:dry'), 2),
        ],
        constraints: $constraints,
        idempotencyKey: IdempotencyKey::of('fo-1'),
    ));

    expect($created->status)->toBe(FulfillmentOrderStatus::Open)
        ->and($created->locationCode)->toBe('wh-cold-1')
        ->and($created->constraints)->toEqual($constraints)
        ->and($created->lines)->toHaveCount(2)
        ->and(fulOutbox('sales.fulfillment_order_created'))->toBe(1);

    // Idempotent replay returns the exact same assignment.
    $replay = $orders->create(new CreateFulfillmentOrder(
        order: $this->order->id,
        locationCode: 'wh-cold-1',
        lines: [
            new LineQuantity(slsLine($this->order, 'fake:dry'), 2),
            new LineQuantity(slsLine($this->order, 'fake:cold'), 3),
        ],
        constraints: $constraints,
        idempotencyKey: IdempotencyKey::of('fo-1'),
    ));

    expect($replay)->toEqual($created)
        ->and(fn () => $orders->create(new CreateFulfillmentOrder(
            order: $this->order->id,
            locationCode: 'wh-cold-2',
            lines: [new LineQuantity(slsLine($this->order, 'fake:cold'), 1)],
            idempotencyKey: IdempotencyKey::of('fo-1'),
        )))->toThrow(IdempotencyConflict::class);

    // Reassign to another cold warehouse before picking starts.
    $reassigned = $orders->reassign(new ReassignFulfillmentOrder($created->id, 'wh-cold-2'));
    expect($reassigned->locationCode)->toBe('wh-cold-2')
        ->and(fulOutbox('sales.fulfillment_order_reassigned'))->toBe(1);

    // Transition Open -> InProgress -> Fulfilled linked to physical Fulfillment.
    $inProgress = $orders->transition(new TransitionFulfillmentOrder($created->id, FulfillmentOrderStatus::InProgress));
    expect($inProgress->status)->toBe(FulfillmentOrderStatus::InProgress)
        ->and(fn () => $orders->reassign(new ReassignFulfillmentOrder($created->id, 'wh-cold-3')))
        ->toThrow(InvalidFulfillmentOrderState::class);

    $fulfillment = slsFulfillments()->create(new CreateFulfillment($this->order->id, [
        new LineQuantity(slsLine($this->order, 'fake:cold'), 3),
        new LineQuantity(slsLine($this->order, 'fake:dry'), 2),
    ], locationCode: 'wh-cold-2'));

    $fulfilled = $orders->transition(new TransitionFulfillmentOrder($created->id, FulfillmentOrderStatus::Fulfilled, $fulfillment->id));

    expect($fulfilled->status)->toBe(FulfillmentOrderStatus::Fulfilled)
        ->and($fulfilled->fulfillment?->equals($fulfillment->id))->toBeTrue()
        ->and($fulfilled->fulfilledAt)->not->toBeNull()
        ->and($orders->forOrder($this->order->id))->toHaveCount(1)
        ->and($orders->list(new ListFulfillmentOrders(locationCode: 'wh-cold-2', status: FulfillmentOrderStatus::Fulfilled))->items)->toHaveCount(1);

    Event::assertDispatched(FulfillmentOrderCreated::class);
    Event::assertDispatched(FulfillmentOrderReassigned::class);
});

it('prevents over-assignment across fulfillment orders and releases quantities on cancellation', function (): void {
    $orders = app(FulfillmentOrders::class);
    $lineId = slsLine($this->order, 'fake:cold');

    $first = $orders->create(new CreateFulfillmentOrder($this->order->id, 'wh-a', [new LineQuantity($lineId, 2)]));

    expect(fn () => $orders->create(new CreateFulfillmentOrder($this->order->id, 'wh-b', [new LineQuantity($lineId, 2)])))
        ->toThrow(QuantityExceedsOutstanding::class);

    $orders->transition(new TransitionFulfillmentOrder($first->id, FulfillmentOrderStatus::Cancelled));

    $replacement = $orders->create(new CreateFulfillmentOrder($this->order->id, 'wh-b', [new LineQuantity($lineId, 3)]));
    expect($replacement->locationCode)->toBe('wh-b')
        ->and($replacement->totalQuantity())->toBe(3);

    $cancelledOrder = slsPlace(slsCartWith(slsChannel('c', 'C-'), ['fake:cold' => 1]));
    slsOrders()->cancel(new CancelOrder($cancelledOrder->id, CancelReason::Customer));

    expect(fn () => $orders->create(new CreateFulfillmentOrder($cancelledOrder->id, 'wh-a', [new LineQuantity(slsLine($cancelledOrder, 'fake:cold'), 1)])))
        ->toThrow(OrderNotFulfillable::class)
        ->and(fn () => $orders->create(new CreateFulfillmentOrder($this->order->id, 'wh-a', [new LineQuantity(slsLine($cancelledOrder, 'fake:cold'), 1)])))
        ->toThrow(LineNotFound::class)
        ->and(fn () => $orders->find(FulfillmentOrderId::generate()))
        ->toThrow(FulfillmentOrderNotFound::class);
});
