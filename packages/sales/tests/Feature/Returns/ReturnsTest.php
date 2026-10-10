<?php

declare(strict_types=1);

use Commerz\Errors\IdempotencyConflict;
use Commerz\Errors\InvalidCommand;
use Commerz\Identity\IdempotencyKey;
use Commerz\Identity\SellableRef;
use Commerz\Ports\Sellables\Enums\SellableKind;
use Commerz\Ports\Stock\Data\RestockLine;
use Commerz\Ports\Stock\Data\RestockStock;
use Commerz\Sales\Common\Data\LineQuantity;
use Commerz\Sales\Common\Exceptions\LineNotFound;
use Commerz\Sales\Fulfillments\Data\CreateFulfillment;
use Commerz\Sales\Orders\Data\CancelOrder;
use Commerz\Sales\Orders\Enums\CancelReason;
use Commerz\Sales\Orders\Enums\FulfillmentState;
use Commerz\Sales\Orders\Enums\OrderState;
use Commerz\Sales\Returns\Data\CancelReturn;
use Commerz\Sales\Returns\Data\ListReturns;
use Commerz\Sales\Returns\Data\ReceiveReturn;
use Commerz\Sales\Returns\Data\RequestReturn;
use Commerz\Sales\Returns\Data\ReturnId;
use Commerz\Sales\Returns\Enums\ReturnStatus;
use Commerz\Sales\Returns\Events\ReturnReceived;
use Commerz\Sales\Returns\Exceptions\RestockExceedsReceived;
use Commerz\Sales\Returns\Exceptions\ReturnExceedsFulfilled;
use Commerz\Sales\Returns\Exceptions\ReturnNotFound;
use Commerz\Sales\Returns\Exceptions\ReturnNotOpen;
use Commerz\Testkit\Commerce;
use Commerz\Testkit\Fakes\FakeStock;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
    $this->channel = slsChannel();
    $this->order = slsPlace(slsCartWith($this->channel, ['fake:a' => 3, 'fake:b' => 1, 'fake:svc' => 1]));
    $this->a = slsLine($this->order, 'fake:a');
    $this->b = slsLine($this->order, 'fake:b');
    $this->svc = slsLine($this->order, 'fake:svc');
    slsFulfillments()->create(new CreateFulfillment($this->order->id, [
        new LineQuantity($this->a, 3),
        new LineQuantity($this->b, 1),
        new LineQuantity($this->svc, 1),
    ], locationCode: FakeStock::LOCATION));
});

it('requests a return of fulfilled units without touching the ledger or the stock', function (): void {
    $return = slsReturns()->request(new RequestReturn($this->order->id, [new LineQuantity($this->a, 2)], reason: 'defect'));
    $order = slsOrders()->find($this->order->id);

    expect($return->status)->toBe(ReturnStatus::Requested)
        ->and($return->reason)->toBe('defect')
        ->and($return->receivedAt)->toBeNull()
        ->and($return->lines)->toHaveCount(1)
        ->and([$return->lines[0]->quantity, $return->lines[0]->restocked])->toBe([2, 0])
        ->and($order->line($this->a)?->returnedQuantity)->toBe(0)
        ->and($this->commerce->stock->restocked)->toBe([])
        ->and(retHistory($order))->toContain('return_requested')
        ->and(slsReturns()->find($return->id)->id->equals($return->id))->toBeTrue();
});

it('counts the open returns against what is still available, and frees them on cancel', function (): void {
    $first = slsReturns()->request(new RequestReturn($this->order->id, [new LineQuantity($this->a, 2)]));

    expect(fn () => slsReturns()->request(new RequestReturn($this->order->id, [new LineQuantity($this->a, 2)])))
        ->toThrow(ReturnExceedsFulfilled::class, '1 units to return');

    slsReturns()->request(new RequestReturn($this->order->id, [new LineQuantity($this->a, 1)]));

    expect(fn () => slsReturns()->request(new RequestReturn($this->order->id, [new LineQuantity($this->a, 1)])))
        ->toThrow(ReturnExceedsFulfilled::class);

    expect(slsReturns()->cancel(new CancelReturn($first->id))->status)->toBe(ReturnStatus::Cancelled);

    slsReturns()->request(new RequestReturn($this->order->id, [new LineQuantity($this->a, 2)]));
});

it('refuses a return of units that were never fulfilled, of another order line, and for a cancelled order', function (): void {
    $other = slsPlace(slsCartWith($this->channel, ['fake:a' => 1]));

    expect(fn () => slsReturns()->request(new RequestReturn($other->id, [new LineQuantity(slsLine($other, 'fake:a'), 1)])))
        ->toThrow(ReturnExceedsFulfilled::class, '0 units to return')
        ->and(fn () => slsReturns()->request(new RequestReturn($other->id, [new LineQuantity($this->a, 1)])))
        ->toThrow(LineNotFound::class);

    slsOrders()->cancel(new CancelOrder($other->id, CancelReason::Customer));

    expect(fn () => slsReturns()->request(new RequestReturn($other->id, [new LineQuantity(slsLine($other, 'fake:a'), 1)])))
        ->toThrow(ReturnExceedsFulfilled::class);
});

it('validates the command', function (): void {
    expect(fn () => new RequestReturn($this->order->id, []))->toThrow(InvalidCommand::class)
        ->and(fn () => new RequestReturn($this->order->id, [new LineQuantity($this->a, 1), new LineQuantity($this->a, 1)]))->toThrow(InvalidCommand::class)
        ->and(fn () => new RequestReturn($this->order->id, [new LineQuantity($this->a, 1)], reason: str_repeat('x', 192)))->toThrow(InvalidCommand::class)
        ->and(fn () => new ReceiveReturn(ReturnId::generate(), [new LineQuantity($this->a, 1), new LineQuantity($this->a, 1)]))->toThrow(InvalidCommand::class)
        ->and(fn () => new ListReturns(limit: 0))->toThrow(InvalidCommand::class);
});

it('repeats a request with the same key as the same return and refuses another body', function (): void {
    $command = fn (int $quantity): RequestReturn => new RequestReturn($this->order->id, [new LineQuantity($this->a, $quantity)], idempotencyKey: IdempotencyKey::of('ret-1'));

    $first = slsReturns()->request($command(1));
    $again = slsReturns()->request($command(1));

    expect($again->id->equals($first->id))->toBeTrue()
        ->and(slsReturns()->list(new ListReturns($this->order->id))->items)->toHaveCount(1)
        ->and(fn () => slsReturns()->request($command(2)))->toThrow(IdempotencyConflict::class);
});

it('receives without restock: the units count as returned and nothing goes back on hand', function (): void {
    Event::fake([ReturnReceived::class]);
    $return = slsReturns()->request(new RequestReturn($this->order->id, [new LineQuantity($this->a, 2)]));

    $received = slsReturns()->receive(new ReceiveReturn($return->id));
    $order = slsOrders()->find($this->order->id);

    expect($received->status)->toBe(ReturnStatus::Received)
        ->and($received->receivedAt)->not->toBeNull()
        ->and($received->lines[0]->restocked)->toBe(0)
        ->and($this->commerce->stock->restocked)->toBe([])
        ->and($order->line($this->a)?->returnedQuantity)->toBe(2)
        ->and($order->fulfillmentState)->toBe(FulfillmentState::PartiallyReturned)
        ->and(retHistory($order))->toContain('return_received')
        ->and(retOutbox('sales.return_received'))->toBe(1);
    Event::assertDispatched(ReturnReceived::class, static fn (ReturnReceived $event): bool => $event->return->equals($return->id));
});

it('restocks only the listed physical units under return:{id}', function (): void {
    $return = slsReturns()->request(new RequestReturn($this->order->id, [
        new LineQuantity($this->a, 2),
        new LineQuantity($this->b, 1),
        new LineQuantity($this->svc, 1),
    ]));

    $received = slsReturns()->receive(new ReceiveReturn($return->id, [
        new LineQuantity($this->a, 1),
        new LineQuantity($this->svc, 1),
    ], locationCode: FakeStock::LOCATION));

    expect($this->commerce->stock->restocked)->toEqual([
        new RestockStock('return:'.$return->id->value, $this->channel, [new RestockLine(SellableRef::fromString('fake:a'), 1)], FakeStock::LOCATION),
    ])
        ->and($received->locationCode)->toBe(FakeStock::LOCATION)
        ->and($received->lines)->toHaveCount(3);

    $restocked = [];

    foreach ($received->lines as $line) {
        $restocked[$line->lineId->value] = $line->restocked;
    }

    expect($restocked[$this->a->value])->toBe(1)
        ->and($restocked[$this->b->value])->toBe(0)
        ->and($restocked[$this->svc->value])->toBe(0);
});

it('refuses to restock more than the return holds, or a line it does not hold, and writes nothing', function (): void {
    $return = slsReturns()->request(new RequestReturn($this->order->id, [new LineQuantity($this->a, 1)]));

    expect(fn () => slsReturns()->receive(new ReceiveReturn($return->id, [new LineQuantity($this->a, 2)])))
        ->toThrow(RestockExceedsReceived::class)
        ->and(fn () => slsReturns()->receive(new ReceiveReturn($return->id, [new LineQuantity($this->b, 1)])))
        ->toThrow(LineNotFound::class)
        ->and(slsReturns()->find($return->id)->status)->toBe(ReturnStatus::Requested)
        ->and(slsOrders()->find($this->order->id)->line($this->a)?->returnedQuantity)->toBe(0)
        ->and($this->commerce->stock->restocked)->toBe([]);
});

it('receives a return once: a repeat returns the view without a second restock', function (): void {
    $return = slsReturns()->request(new RequestReturn($this->order->id, [new LineQuantity($this->a, 2)]));
    $command = new ReceiveReturn($return->id, [new LineQuantity($this->a, 2)]);

    $first = slsReturns()->receive($command);
    $again = slsReturns()->receive($command);

    expect($again->status)->toBe(ReturnStatus::Received)
        ->and($again->receivedAt?->format('U.u'))->toBe($first->receivedAt?->format('U.u'))
        ->and($this->commerce->stock->restocked)->toHaveCount(1)
        ->and(slsOrders()->find($this->order->id)->line($this->a)?->returnedQuantity)->toBe(2)
        ->and(retOutbox('sales.return_received'))->toBe(1);
});

it('cancels a requested return, repeats harmlessly, and closes a received or cancelled one for the other path', function (): void {
    $cancelled = slsReturns()->request(new RequestReturn($this->order->id, [new LineQuantity($this->a, 1)]));
    $received = slsReturns()->request(new RequestReturn($this->order->id, [new LineQuantity($this->b, 1)]));

    expect(slsReturns()->cancel(new CancelReturn($cancelled->id))->status)->toBe(ReturnStatus::Cancelled)
        ->and(slsReturns()->cancel(new CancelReturn($cancelled->id))->status)->toBe(ReturnStatus::Cancelled)
        ->and(fn () => slsReturns()->receive(new ReceiveReturn($cancelled->id)))->toThrow(ReturnNotOpen::class, 'cancelled');

    slsReturns()->receive(new ReceiveReturn($received->id));

    expect(fn () => slsReturns()->cancel(new CancelReturn($received->id)))->toThrow(ReturnNotOpen::class, 'received')
        ->and(retHistory(slsOrders()->find($this->order->id)))->toContain('return_cancelled');
});

it('moves the fulfillment state from partially returned to returned', function (): void {
    $first = slsReturns()->request(new RequestReturn($this->order->id, [new LineQuantity($this->a, 3)]));
    slsReturns()->receive(new ReceiveReturn($first->id));

    expect(slsOrders()->find($this->order->id)->fulfillmentState)->toBe(FulfillmentState::PartiallyReturned);

    $rest = slsReturns()->request(new RequestReturn($this->order->id, [new LineQuantity($this->b, 1), new LineQuantity($this->svc, 1)]));
    slsReturns()->receive(new ReceiveReturn($rest->id));

    expect(slsOrders()->find($this->order->id)->fulfillmentState)->toBe(FulfillmentState::Returned);
});

it('takes a return of a completed order and leaves it completed', function (): void {
    slsOrderPayment($this->order, 'captured', $this->order->amountDue->amountMinor, capturedMinor: $this->order->amountDue->amountMinor);
    expect(slsRefresh($this->order)->state)->toBe(OrderState::Completed);

    $return = slsReturns()->request(new RequestReturn($this->order->id, [new LineQuantity($this->a, 1)]));
    slsReturns()->receive(new ReceiveReturn($return->id));
    $order = slsOrders()->find($this->order->id);

    expect([$order->state, $order->fulfillmentState])->toBe([OrderState::Completed, FulfillmentState::PartiallyReturned]);
});

it('finds and lists returns, and refuses an unknown one', function (): void {
    $one = slsReturns()->request(new RequestReturn($this->order->id, [new LineQuantity($this->a, 1)]));
    $two = slsReturns()->request(new RequestReturn($this->order->id, [new LineQuantity($this->b, 1)]));
    slsReturns()->cancel(new CancelReturn($two->id));

    $page = slsReturns()->list(new ListReturns($this->order->id, limit: 1));
    $next = slsReturns()->list(new ListReturns($this->order->id, limit: 1, after: $page->next));

    expect($page->items)->toHaveCount(1)
        ->and($page->items[0]->id->equals($two->id))->toBeTrue()
        ->and($next->items[0]->id->equals($one->id))->toBeTrue()
        ->and(slsReturns()->list(new ListReturns(status: ReturnStatus::Requested))->items)->toHaveCount(1)
        ->and(fn () => slsReturns()->find(ReturnId::generate()))->toThrow(ReturnNotFound::class)
        ->and(fn () => slsReturns()->receive(new ReceiveReturn(ReturnId::generate())))->toThrow(ReturnNotFound::class);
});
