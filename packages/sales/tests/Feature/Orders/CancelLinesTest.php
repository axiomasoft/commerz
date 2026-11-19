<?php

declare(strict_types=1);

use Commerz\Errors\InvalidCommand;
use Commerz\Identity\SellableRef;
use Commerz\Money\Money;
use Commerz\Ports\Promotions\Data\RestorePromotionUsage;
use Commerz\Ports\Promotions\Enums\RestoreReason;
use Commerz\Ports\Sellables\Enums\SellableKind;
use Commerz\Ports\Stock\Data\ReleaseReservation;
use Commerz\Sales\Carts\Data\EnterCode;
use Commerz\Sales\Common\Data\LineQuantity;
use Commerz\Sales\Common\Exceptions\LineNotFound;
use Commerz\Sales\Common\Exceptions\QuantityExceedsOutstanding;
use Commerz\Sales\Fulfillments\Data\CreateFulfillment;
use Commerz\Sales\Orders\Data\CancelOrder;
use Commerz\Sales\Orders\Data\CancelOrderLines;
use Commerz\Sales\Orders\Data\OrderEventView;
use Commerz\Sales\Orders\Data\OrderLineId;
use Commerz\Sales\Orders\Enums\CancelReason;
use Commerz\Sales\Orders\Enums\FulfillmentState;
use Commerz\Sales\Orders\Enums\OrderState;
use Commerz\Sales\Orders\Enums\PaymentState;
use Commerz\Sales\Orders\Events\OrderCompleted;
use Commerz\Sales\Orders\Exceptions\OrderNotCancellable;
use Commerz\Schema\TableNames;
use Commerz\Testkit\Commerce;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

require_once __DIR__.'/../Helpers.php';

uses(RefreshDatabase::class, SlsEnvironment::class);

beforeEach(function (): void {
    $this->commerce = Commerce::fake();
    $this->commerce->sellables->add(
        slsSnapshot('fake:a', '100.00'),
        slsSnapshot('fake:b', '10.00'),
        slsSnapshot('fake:svc', '50.00', SellableKind::Service),
    );
    slsConfig(['checkout' => ['require_delivery' => false]]);
    $this->web = slsChannel();
});

function clnOutbox(string $type): int
{
    return DB::table(app(TableNames::class)->table('outbox_messages'))->where('type', $type)->count();
}

it('cancels some units, releases their stock and lowers the amount due by their exact share', function (): void {
    $placed = slsPlace(slsCartWith($this->web, ['fake:a' => 3, 'fake:svc' => 1]));
    $demand = 'order:'.$placed->id->value;

    $order = slsOrders()->cancelLines(new CancelOrderLines($placed->id, [
        new LineQuantity(slsLine($placed, 'fake:a'), 1),
        new LineQuantity(slsLine($placed, 'fake:svc'), 1),
    ], CancelReason::OutOfStock));

    expect([$order->state, $order->paymentState, $order->cancelReason])->toBe([OrderState::Placed, PaymentState::Unpaid, null])
        ->and($order->amountDue)->toEqual(Money::of('200.00', 'RUB'))
        ->and($order->total)->toEqual(Money::of('350.00', 'RUB'))
        ->and($order->line(slsLine($placed, 'fake:a'))?->outstanding())->toBe(2)
        ->and($order->line(slsLine($placed, 'fake:svc'))?->cancelledQuantity)->toBe(1)
        // Only the physical line holds stock.
        ->and($this->commerce->stock->released)->toEqual([new ReleaseReservation($demand, SellableRef::fromString('fake:a'), 1)])
        ->and($this->commerce->stock->held($demand))->toBe(['fake:a' => 2])
        ->and($this->commerce->promotions->restored)->toBe([])
        ->and(clnOutbox('sales.order_cancelled'))->toBe(0)
        ->and(array_map(static fn (OrderEventView $event): array => [$event->type, $event->data], array_slice(slsOrders()->history($order->id), 1)))
        ->toEqual(array_map(static fn (string $ref): array => ['line_cancelled', [
            'line_id' => slsLine($placed, $ref)->value,
            'quantity' => 1,
            'reason' => 'out_of_stock',
        ]], $placed->lines[0]->id->value < $placed->lines[1]->id->value ? ['fake:a', 'fake:svc'] : ['fake:svc', 'fake:a']));
});

it('cancels the order when every unit left is cancelled', function (): void {
    $cart = slsCartWith($this->web, ['fake:a' => 2, 'fake:svc' => 1]);
    slsCarts()->enterCode(new EnterCode($cart->id, 'promo:p1'));
    $placed = slsPlace($cart);
    $demand = 'order:'.$placed->id->value;
    slsOrders()->cancelLines(new CancelOrderLines($placed->id, [new LineQuantity(slsLine($placed, 'fake:a'), 1)], CancelReason::Customer));

    $order = slsOrders()->cancelLines(new CancelOrderLines($placed->id, [
        new LineQuantity(slsLine($placed, 'fake:a'), 1),
        new LineQuantity(slsLine($placed, 'fake:svc'), 1),
    ], CancelReason::Customer));

    expect([$order->state, $order->cancelReason, $order->paymentState])->toBe([OrderState::Cancelled, CancelReason::Customer, PaymentState::Unpaid])
        ->and($order->amountDue->isZero())->toBeTrue()
        ->and($this->commerce->stock->released)->toEqual([
            new ReleaseReservation($demand, SellableRef::fromString('fake:a'), 1),
            new ReleaseReservation($demand),
        ])
        ->and($this->commerce->stock->held($demand))->toBe([])
        ->and($this->commerce->promotions->restored)->toEqual([new RestorePromotionUsage($demand, RestoreReason::Cancelled)])
        ->and(clnOutbox('sales.order_cancelled'))->toBe(1);
});

it('completes a paid order whose units left after a fulfillment are cancelled (ORD-03)', function (): void {
    Event::fake([OrderCompleted::class]);
    $placed = slsPlace(slsCartWith($this->web, ['fake:a' => 3]));
    $line = slsLine($placed, 'fake:a');
    slsLedger()->fulfill($placed->id, new LineQuantity($line, 2));
    slsOrderPayment($placed, 'captured', 20000, capturedMinor: 20000);
    expect(slsRefresh($placed))->paymentState->toBe(PaymentState::PartiallyPaid)
        ->fulfillmentState->toBe(FulfillmentState::PartiallyFulfilled);

    $order = slsOrders()->cancelLines(new CancelOrderLines($placed->id, [new LineQuantity($line, 1)], CancelReason::OutOfStock));

    expect([$order->state, $order->paymentState, $order->fulfillmentState])->toBe([OrderState::Completed, PaymentState::Paid, FulfillmentState::Fulfilled])
        ->and($order->amountDue)->toEqual(Money::of('200.00', 'RUB'))
        ->and($order->completedAt)->not->toBeNull()
        ->and($order->line($line)?->outstanding())->toBe(0)
        ->and(clnOutbox('sales.order_completed'))->toBe(1)
        ->and(clnOutbox('sales.payment_requires_refund'))->toBe(0)
        ->and(array_map(static fn (OrderEventView $event): string => $event->type, slsOrders()->history($order->id)))
        ->toBe(['placed', 'fulfillment_state_changed', 'payment_state_changed', 'line_cancelled', 'fulfillment_state_changed', 'payment_state_changed', 'completed']);
    Event::assertDispatched(OrderCompleted::class, static fn (OrderCompleted $event): bool => $event->order->equals($order->id));
});

it('completes a paid order when the rest of a partly fulfilled line is cancelled, and refuses what was shipped (ORD-03)', function (): void {
    $placed = slsPlace(slsCartWith($this->web, ['fake:a' => 3]));
    $line = slsLine($placed, 'fake:a');
    $demand = 'order:'.$placed->id->value;
    slsOrderPayment($placed, 'captured', 20000, capturedMinor: 20000);
    slsRefresh($placed);
    slsFulfillments()->create(new CreateFulfillment($placed->id, [new LineQuantity($line, 2)]));

    expect(fn () => slsOrders()->cancelLines(new CancelOrderLines($placed->id, [new LineQuantity($line, 2)], CancelReason::Customer)))
        ->toThrow(QuantityExceedsOutstanding::class)
        ->and(fn () => slsOrders()->cancel(new CancelOrder($placed->id, CancelReason::Customer)))->toThrow(OrderNotCancellable::class, 'fulfilled');

    $order = slsOrders()->cancelLines(new CancelOrderLines($placed->id, [new LineQuantity($line, 1)], CancelReason::OutOfStock));

    // The fulfilled units left the reservation, the cancelled one goes back; nothing is held for the order any more.
    expect([$order->state, $order->paymentState, $order->fulfillmentState])->toBe([OrderState::Completed, PaymentState::Paid, FulfillmentState::Fulfilled])
        ->and($order->line($line))->cancelledQuantity->toBe(1)->fulfilledQuantity->toBe(2)
        ->and($this->commerce->stock->consumed)->toHaveCount(1)
        ->and($this->commerce->stock->released)->toEqual([new ReleaseReservation($demand, SellableRef::fromString('fake:a'), 1)])
        ->and($this->commerce->stock->held($demand))->toBe([]);
});

it('announces the refund of the share a paid order no longer owes', function (): void {
    $placed = slsPlace(slsCartWith($this->web, ['fake:a' => 3]));
    slsOrderPayment($placed, 'captured', 30000, capturedMinor: 30000);
    slsRefresh($placed);

    $order = slsOrders()->cancelLines(new CancelOrderLines($placed->id, [new LineQuantity(slsLine($placed, 'fake:a'), 1)], CancelReason::Customer));

    expect([$order->state, $order->paymentState])->toBe([OrderState::Placed, PaymentState::Paid])
        ->and($order->amountDue)->toEqual(Money::of('200.00', 'RUB'))
        ->and(json_decode((string) DB::table(app(TableNames::class)->table('outbox_messages'))->where('type', 'sales.payment_requires_refund')->sole()->payload, true))
        ->toMatchArray(['amount_minor' => 10000, 'currency' => 'RUB']);

    slsOrders()->cancelLines(new CancelOrderLines($placed->id, [new LineQuantity(slsLine($placed, 'fake:a'), 1)], CancelReason::Customer));

    // Each change announces only what it adds to the refund.
    expect(DB::table(app(TableNames::class)->table('outbox_messages'))->where('type', 'sales.payment_requires_refund')->orderBy('id')->pluck('payload')
        ->map(static fn (string $payload): int => json_decode($payload, true)['amount_minor'])->all())->toBe([10000, 10000]);
});

it('cancels nothing when one of the lines has too few units left', function (): void {
    $placed = slsPlace(slsCartWith($this->web, ['fake:a' => 2, 'fake:b' => 1]));

    expect(fn () => slsOrders()->cancelLines(new CancelOrderLines($placed->id, [
        new LineQuantity(slsLine($placed, 'fake:a'), 1),
        new LineQuantity(slsLine($placed, 'fake:b'), 2),
    ], CancelReason::Customer)))->toThrow(QuantityExceedsOutstanding::class)
        ->and(slsOrders()->find($placed->id))->toEqual($placed)
        ->and($this->commerce->stock->released)->toBe([]);
});

it('refuses unknown lines, cancelled orders and repeated lines', function (): void {
    $placed = slsPlace(slsCartWith($this->web, ['fake:a' => 2]));
    $line = new LineQuantity(slsLine($placed, 'fake:a'), 1);

    expect(fn () => slsOrders()->cancelLines(new CancelOrderLines($placed->id, [new LineQuantity(OrderLineId::generate(), 1)], CancelReason::Customer)))
        ->toThrow(LineNotFound::class)
        ->and(fn () => new CancelOrderLines($placed->id, [$line, $line], CancelReason::Customer))->toThrow(InvalidCommand::class, 'each line once')
        ->and(fn () => new CancelOrderLines($placed->id, [], CancelReason::Customer))->toThrow(InvalidCommand::class, 'empty');

    slsOrders()->cancel(new CancelOrder($placed->id, CancelReason::Customer));

    expect(fn () => slsOrders()->cancelLines(new CancelOrderLines($placed->id, [$line], CancelReason::Customer)))
        ->toThrow(OrderNotCancellable::class, 'cancelled');
});
