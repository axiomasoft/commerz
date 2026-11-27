<?php

declare(strict_types=1);

use Commerz\Errors\IdempotencyConflict;
use Commerz\Errors\InvalidCommand;
use Commerz\Identity\IdempotencyKey;
use Commerz\Identity\SellableRef;
use Commerz\Pagination\Page;
use Commerz\Ports\Sellables\Enums\SellableKind;
use Commerz\Ports\Stock\Data\ConsumeReservation;
use Commerz\Ports\Stock\Data\ReleaseReservation;
use Commerz\Ports\Stock\Exceptions\ReservationNotFound;
use Commerz\Sales\Common\Data\LineQuantity;
use Commerz\Sales\Common\Exceptions\LineNotFound;
use Commerz\Sales\Common\Exceptions\QuantityExceedsOutstanding;
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
use Commerz\Sales\Fulfillments\Exceptions\FulfillmentNotFound;
use Commerz\Sales\Fulfillments\Exceptions\FulfillmentNotShipped;
use Commerz\Sales\Fulfillments\Exceptions\OrderNotFulfillable;
use Commerz\Sales\Orders\Data\CancelOrder;
use Commerz\Sales\Orders\Enums\CancelReason;
use Commerz\Sales\Orders\Enums\FulfillmentState;
use Commerz\Sales\Orders\Enums\OrderState;
use Commerz\Sales\Orders\Enums\PaymentState;
use Commerz\Schema\TableNames;
use Commerz\Testkit\Commerce;
use Commerz\Testkit\Fakes\FakeStock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

require_once __DIR__.'/Helpers.php';

uses(RefreshDatabase::class, SlsEnvironment::class);

beforeEach(function (): void {
    $this->commerce = Commerce::fake();
    $this->commerce->sellables->add(
        slsSnapshot('fake:a', '100.00'),
        slsSnapshot('fake:b', '30.00'),
        slsSnapshot('fake:svc', '50.00', SellableKind::Service),
    );
    slsConfig(['checkout' => ['require_delivery' => false]]);
    $this->order = slsPlace(slsCartWith(slsChannel(), ['fake:a' => 3, 'fake:b' => 1, 'fake:svc' => 1]));
    $this->demand = 'order:'.$this->order->id->value;
});

it('fulfils units, takes only the physical lines out of the reservation and refreshes the order', function (): void {
    Event::fake([FulfillmentCreated::class]);

    $created = slsFulfillments()->create(new CreateFulfillment($this->order->id, [
        new LineQuantity(slsLine($this->order, 'fake:a'), 2),
        new LineQuantity(slsLine($this->order, 'fake:svc'), 1),
    ], locationCode: FakeStock::LOCATION));

    $consumed = $this->commerce->stock->consumed;
    $order = slsOrders()->find($this->order->id);

    expect($created->status)->toBe(FulfillmentStatus::Created)
        ->and($created->locationCode)->toBe(FakeStock::LOCATION)
        ->and($created->tracking)->toBeNull()
        ->and($created->lines)->toHaveCount(2)
        // One consume, for the physical line only, with the document as its reference.
        ->and($consumed)->toEqual([new ConsumeReservation($this->demand, SellableRef::fromString('fake:a'), 2, 'fulfillment:'.$created->id->value, FakeStock::LOCATION)])
        ->and($this->commerce->stock->held($this->demand))->toBe(['fake:a' => 1, 'fake:b' => 1])
        ->and($order->line(slsLine($this->order, 'fake:a'))?->fulfilledQuantity)->toBe(2)
        ->and($order->line(slsLine($this->order, 'fake:svc'))?->fulfilledQuantity)->toBe(1)
        ->and([$order->state, $order->fulfillmentState])->toBe([OrderState::Placed, FulfillmentState::PartiallyFulfilled])
        ->and(fulHistory($order))->toContain('fulfillment_created', 'fulfillment_state_changed');
    Event::assertDispatched(FulfillmentCreated::class, static fn (FulfillmentCreated $event): bool => $event->fulfillment->equals($created->id) && $event->order->equals($order->id));
});

it('lets the stock choose the location when none is given', function (): void {
    $created = slsFulfillments()->create(new CreateFulfillment($this->order->id, [new LineQuantity(slsLine($this->order, 'fake:b'), 1)]));

    expect($created->locationCode)->toBeNull()
        ->and($this->commerce->stock->consumed[0]->locationCode)->toBeNull();
});

it('fulfils a service without touching the stock', function (): void {
    $created = slsFulfillments()->create(new CreateFulfillment($this->order->id, [new LineQuantity(slsLine($this->order, 'fake:svc'), 1)]));

    expect($this->commerce->stock->consumed)->toBe([])
        ->and($created->lines)->toHaveCount(1)
        ->and($this->commerce->stock->held($this->demand))->toBe(['fake:a' => 3, 'fake:b' => 1]);
});

it('writes nothing when a line has fewer units outstanding than asked', function (): void {
    expect(fn () => slsFulfillments()->create(new CreateFulfillment($this->order->id, [
        new LineQuantity(slsLine($this->order, 'fake:a'), 1),
        new LineQuantity(slsLine($this->order, 'fake:svc'), 2),
    ])))->toThrow(QuantityExceedsOutstanding::class)
        ->and(fulCount('fulfillments'))->toBe(0)
        ->and(fulCount('fulfillment_lines'))->toBe(0)
        ->and($this->commerce->stock->consumed)->toBe([])
        ->and(slsOrders()->find($this->order->id))->toEqual($this->order);
});

it('counts the units of earlier fulfillments against the outstanding', function (): void {
    slsFulfillments()->create(new CreateFulfillment($this->order->id, [new LineQuantity(slsLine($this->order, 'fake:a'), 2)]));

    $order = $this->order;
    $attempt = static fn (int $quantity) => fn () => slsFulfillments()->create(new CreateFulfillment($order->id, [new LineQuantity(slsLine($order, 'fake:a'), $quantity)]));

    expect($attempt(2))->toThrow(QuantityExceedsOutstanding::class)
        ->and(fulCount('fulfillments'))->toBe(1);

    expect($attempt(1))->not->toThrow(QuantityExceedsOutstanding::class)
        ->and(fulCount('fulfillments'))->toBe(2);
});

it('rolls the fulfillment back when the reservation holds fewer units than are fulfilled', function (): void {
    $this->commerce->stock->release(new ReleaseReservation($this->demand, SellableRef::fromString('fake:b')));

    expect(fn () => slsFulfillments()->create(new CreateFulfillment($this->order->id, [
        new LineQuantity(slsLine($this->order, 'fake:a'), 1),
        new LineQuantity(slsLine($this->order, 'fake:b'), 1),
    ])))->toThrow(ReservationNotFound::class)
        ->and(fulCount('fulfillments'))->toBe(0)
        ->and(slsOrders()->find($this->order->id)->line(slsLine($this->order, 'fake:a'))?->fulfilledQuantity)->toBe(0);
});

it('refuses an unknown line and a line of another order', function (): void {
    $other = slsPlace(slsCartWith(slsChannel('other', 'O-'), ['fake:a' => 1]));

    expect(fn () => slsFulfillments()->create(new CreateFulfillment($this->order->id, [new LineQuantity(slsLine($other, 'fake:a'), 1)])))
        ->toThrow(LineNotFound::class)
        ->and(fulCount('fulfillments'))->toBe(0);
});

it('does not fulfil a cancelled or a completed order', function (): void {
    $cancelled = slsPlace(slsCartWith(slsChannel('c', 'C-'), ['fake:a' => 1]));
    slsOrders()->cancel(new CancelOrder($cancelled->id, CancelReason::Customer));
    $line = new LineQuantity(slsLine($cancelled, 'fake:a'), 1);

    expect(fn () => slsFulfillments()->create(new CreateFulfillment($cancelled->id, [$line])))
        ->toThrow(OrderNotFulfillable::class, 'cancelled');

    slsOrderPayment($this->order, 'captured', $this->order->amountDue->amountMinor, capturedMinor: $this->order->amountDue->amountMinor);
    slsFulfillments()->create(new CreateFulfillment($this->order->id, [
        new LineQuantity(slsLine($this->order, 'fake:a'), 3),
        new LineQuantity(slsLine($this->order, 'fake:b'), 1),
        new LineQuantity(slsLine($this->order, 'fake:svc'), 1),
    ]));

    expect(slsOrders()->find($this->order->id)->state)->toBe(OrderState::Completed)
        ->and(fn () => slsFulfillments()->create(new CreateFulfillment($this->order->id, [new LineQuantity(slsLine($this->order, 'fake:a'), 1)])))
        ->toThrow(OrderNotFulfillable::class, 'completed');
});

it('derives partially fulfilled, fulfilled and, once paid, completed', function (): void {
    slsFulfillments()->create(new CreateFulfillment($this->order->id, [new LineQuantity(slsLine($this->order, 'fake:a'), 3)]));

    expect(slsOrders()->find($this->order->id))->fulfillmentState->toBe(FulfillmentState::PartiallyFulfilled);

    slsFulfillments()->create(new CreateFulfillment($this->order->id, [
        new LineQuantity(slsLine($this->order, 'fake:b'), 1),
        new LineQuantity(slsLine($this->order, 'fake:svc'), 1),
    ]));

    // Everything left, but not paid yet: the order waits for its money.
    expect(slsOrders()->find($this->order->id))->state->toBe(OrderState::Placed)->fulfillmentState->toBe(FulfillmentState::Fulfilled)
        ->and(fulOutbox('sales.order_completed'))->toBe(0);

    slsOrderPayment($this->order, 'captured', $this->order->amountDue->amountMinor, capturedMinor: $this->order->amountDue->amountMinor);
    $order = slsRefresh($this->order);

    expect([$order->state, $order->paymentState, $order->fulfillmentState])->toBe([OrderState::Completed, PaymentState::Paid, FulfillmentState::Fulfilled])
        ->and(fulOutbox('sales.order_completed'))->toBe(1);
});

it('completes a paid order with the fulfillment that takes its last units', function (): void {
    slsOrderPayment($this->order, 'captured', $this->order->amountDue->amountMinor, capturedMinor: $this->order->amountDue->amountMinor);
    slsRefresh($this->order);

    $created = slsFulfillments()->create(new CreateFulfillment($this->order->id, [
        new LineQuantity(slsLine($this->order, 'fake:a'), 3),
        new LineQuantity(slsLine($this->order, 'fake:b'), 1),
        new LineQuantity(slsLine($this->order, 'fake:svc'), 1),
    ]));
    $order = slsOrders()->find($this->order->id);

    expect([$order->state, $order->fulfillmentState])->toBe([OrderState::Completed, FulfillmentState::Fulfilled])
        ->and($order->completedAt)->not->toBeNull()
        ->and(fulOutbox('sales.order_completed'))->toBe(1)
        ->and($this->commerce->stock->held($this->demand))->toBe([])
        ->and($created->lines)->toHaveCount(3);
});

it('returns the same document for a repeated idempotency key and consumes once', function (): void {
    $command = fn (int $quantity = 2): CreateFulfillment => new CreateFulfillment(
        $this->order->id,
        [new LineQuantity(slsLine($this->order, 'fake:a'), $quantity)],
        locationCode: FakeStock::LOCATION,
        idempotencyKey: IdempotencyKey::of('ful-1'),
    );

    $first = slsFulfillments()->create($command());
    $second = slsFulfillments()->create($command());

    expect($second)->toEqual($first)
        ->and($this->commerce->stock->consumed)->toHaveCount(1)
        ->and(fulCount('fulfillments'))->toBe(1)
        ->and(slsOrders()->find($this->order->id)->line(slsLine($this->order, 'fake:a'))?->fulfilledQuantity)->toBe(2)
        ->and(fn () => slsFulfillments()->create($command(1)))->toThrow(IdempotencyConflict::class);
});

it('treats the lines of a keyed command in any order as the same request', function (): void {
    $a = new LineQuantity(slsLine($this->order, 'fake:a'), 1);
    $b = new LineQuantity(slsLine($this->order, 'fake:b'), 1);

    $first = slsFulfillments()->create(new CreateFulfillment($this->order->id, [$a, $b], idempotencyKey: IdempotencyKey::of('ful-2')));
    $second = slsFulfillments()->create(new CreateFulfillment($this->order->id, [$b, $a], idempotencyKey: IdempotencyKey::of('ful-2')));

    expect($second->id->equals($first->id))->toBeTrue()
        ->and(fulCount('fulfillments'))->toBe(1);
});

it('consumes in line order whatever the order of the command', function (): void {
    $a = new LineQuantity(slsLine($this->order, 'fake:a'), 1);
    $b = new LineQuantity(slsLine($this->order, 'fake:b'), 1);

    slsFulfillments()->create(new CreateFulfillment($this->order->id, [$a, $b]));
    $refs = array_map(static fn (ConsumeReservation $consume): string => (string) $consume->sellable, $this->commerce->stock->consumed);
    $ids = [slsLine($this->order, 'fake:a')->value => 'fake:a', slsLine($this->order, 'fake:b')->value => 'fake:b'];
    ksort($ids);

    expect($refs)->toBe(array_values($ids));
});

it('ships a created fulfillment with the carrier and tracking handed over', function (): void {
    Event::fake([FulfillmentShipped::class]);
    $created = slsFulfillments()->create(new CreateFulfillment($this->order->id, [new LineQuantity(slsLine($this->order, 'fake:a'), 1)]));

    $shipped = slsFulfillments()->ship(new ShipFulfillment($created->id, 'manual', 'RB123'));

    expect($shipped->status)->toBe(FulfillmentStatus::Shipped)
        ->and([$shipped->carrier, $shipped->tracking, $shipped->labelRef, $shipped->methodCode])->toBe(['manual', 'RB123', null, null])
        ->and($shipped->shippedAt)->not->toBeNull()
        ->and(fulOutbox('sales.fulfillment_shipped'))->toBe(1)
        ->and(slsJsonObject(DB::table(app(TableNames::class)->table('outbox_messages'))->where('type', 'sales.fulfillment_shipped')->value('payload')))
        ->toMatchArray(['order_id' => $this->order->id->value, 'number' => $this->order->number, 'fulfillment_id' => $created->id->value, 'carrier' => 'manual', 'tracking' => 'RB123'])
        ->and(fulHistory($this->order))->toContain('fulfillment_shipped');
    Event::assertDispatched(FulfillmentShipped::class, static fn (FulfillmentShipped $event): bool => $event->tracking === 'RB123' && $event->fulfillment->equals($created->id));
});

it('ships again with the same carrier and tracking without a second shipment, and never overwrites another', function (): void {
    $created = slsFulfillments()->create(new CreateFulfillment($this->order->id, [new LineQuantity(slsLine($this->order, 'fake:a'), 1)]));
    $first = slsFulfillments()->ship(new ShipFulfillment($created->id, 'manual', 'RB123'));

    $again = slsFulfillments()->ship(new ShipFulfillment($created->id, 'manual', 'RB123'));

    expect($again)->toEqual($first)
        ->and(fulOutbox('sales.fulfillment_shipped'))->toBe(1)
        ->and(fn () => slsFulfillments()->ship(new ShipFulfillment($created->id, 'manual', 'OTHER')))->toThrow(FulfillmentAlreadyShipped::class)
        ->and(fn () => slsFulfillments()->ship(new ShipFulfillment($created->id)))->toThrow(FulfillmentAlreadyShipped::class)
        ->and(slsFulfillments()->find($created->id)->tracking)->toBe('RB123');
});

it('ships without a tracking number when the operator has none', function (): void {
    $created = slsFulfillments()->create(new CreateFulfillment($this->order->id, [new LineQuantity(slsLine($this->order, 'fake:svc'), 1)]));

    expect(slsFulfillments()->ship(new ShipFulfillment($created->id)))->status->toBe(FulfillmentStatus::Shipped)->tracking->toBeNull()
        ->and(slsFulfillments()->ship(new ShipFulfillment($created->id))->tracking)->toBeNull();
});

it('delivers a shipped fulfillment, and only that', function (): void {
    $created = slsFulfillments()->create(new CreateFulfillment($this->order->id, [new LineQuantity(slsLine($this->order, 'fake:a'), 1)]));

    expect(fn () => slsFulfillments()->markDelivered(new MarkFulfillmentDelivered($created->id)))->toThrow(FulfillmentNotShipped::class);

    slsFulfillments()->ship(new ShipFulfillment($created->id, 'manual', 'RB1'));
    $delivered = slsFulfillments()->markDelivered(new MarkFulfillmentDelivered($created->id));

    expect($delivered->status)->toBe(FulfillmentStatus::Delivered)
        ->and($delivered->deliveredAt)->not->toBeNull()
        ->and(slsFulfillments()->markDelivered(new MarkFulfillmentDelivered($created->id)))->toEqual($delivered)
        ->and(slsFulfillments()->ship(new ShipFulfillment($created->id, 'manual', 'RB1'))->status)->toBe(FulfillmentStatus::Delivered)
        ->and(array_count_values(fulHistory($this->order))['fulfillment_delivered'])->toBe(1);
});

it('finds and lists fulfillments newest first, by order and status', function (): void {
    $first = slsFulfillments()->create(new CreateFulfillment($this->order->id, [new LineQuantity(slsLine($this->order, 'fake:a'), 1)]));
    $second = slsFulfillments()->create(new CreateFulfillment($this->order->id, [new LineQuantity(slsLine($this->order, 'fake:a'), 1)]));
    $third = slsFulfillments()->create(new CreateFulfillment($this->order->id, [new LineQuantity(slsLine($this->order, 'fake:a'), 1)]));
    slsFulfillments()->ship(new ShipFulfillment($second->id, 'manual', 'T'));
    $ids = static function (Page $page): array {
        $values = [];

        foreach ($page->items as $view) {
            if (! $view instanceof FulfillmentView) {
                throw new RuntimeException('Expected a fulfillment.');
            }

            $values[] = $view->id->value;
        }

        return $values;
    };

    $page = slsFulfillments()->list(new ListFulfillments($this->order->id, limit: 2));
    $rest = slsFulfillments()->list(new ListFulfillments($this->order->id, after: $page->next, limit: 2));

    expect(slsFulfillments()->find($first->id))->toEqual($first)
        ->and($ids($page))->toBe([$third->id->value, $second->id->value])
        ->and($page->hasMore())->toBeTrue()
        ->and($ids($rest))->toBe([$first->id->value])
        ->and($rest->hasMore())->toBeFalse()
        ->and($ids(slsFulfillments()->list(new ListFulfillments(status: FulfillmentStatus::Shipped))))->toBe([$second->id->value])
        ->and(slsFulfillments()->list(new ListFulfillments(slsPlace(slsCartWith(slsChannel('o', 'O-'), ['fake:a' => 1]))->id))->items)->toBe([])
        ->and(fn () => slsFulfillments()->find(FulfillmentId::generate()))->toThrow(FulfillmentNotFound::class)
        ->and(fn () => slsFulfillments()->ship(new ShipFulfillment(FulfillmentId::generate())))->toThrow(FulfillmentNotFound::class)
        ->and(fn () => slsFulfillments()->markDelivered(new MarkFulfillmentDelivered(FulfillmentId::generate())))->toThrow(FulfillmentNotFound::class);
});

it('rejects malformed commands', function (): void {
    $order = $this->order->id;
    $line = new LineQuantity(slsLine($this->order, 'fake:a'), 1);

    expect(fn () => new CreateFulfillment($order, []))->toThrow(InvalidCommand::class, 'empty')
        ->and(fn () => new CreateFulfillment($order, [$line, $line]))->toThrow(InvalidCommand::class, 'each line once')
        ->and(fn () => new CreateFulfillment($order, [$line], locationCode: 'Main Hall'))->toThrow(InvalidCommand::class)
        ->and(fn () => new ShipFulfillment(FulfillmentId::generate(), ' '))->toThrow(InvalidCommand::class)
        ->and(fn () => new ShipFulfillment(FulfillmentId::generate(), tracking: "a\0b"))->toThrow(InvalidCommand::class)
        ->and(fn () => new BookFulfillment(FulfillmentId::generate(), lengthMm: 0))->toThrow(InvalidCommand::class, 'positive')
        ->and(fn () => new ReflectionClass(BookFulfillment::class)->newInstance(FulfillmentId::generate(), null, null, null, ['k' => [1]]))->toThrow(InvalidCommand::class, 'JSON scalars')
        ->and(fn () => new ListFulfillments(limit: 0))->toThrow(InvalidCommand::class);
});
