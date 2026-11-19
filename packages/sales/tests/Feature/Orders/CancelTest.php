<?php

declare(strict_types=1);

use Commerz\Identity\ActorRef;
use Commerz\Money\Money;
use Commerz\Ports\Promotions\Data\RestorePromotionUsage;
use Commerz\Ports\Promotions\Enums\RestoreReason;
use Commerz\Ports\Sellables\Enums\SellableKind;
use Commerz\Ports\Stock\Data\ReleaseReservation;
use Commerz\Sales\Carts\Data\EnterCode;
use Commerz\Sales\Common\Data\LineQuantity;
use Commerz\Sales\Orders\Data\CancelOrder;
use Commerz\Sales\Orders\Data\OrderEventView;
use Commerz\Sales\Orders\Data\OrderId;
use Commerz\Sales\Orders\Data\OrderLineView;
use Commerz\Sales\Orders\Enums\CancelReason;
use Commerz\Sales\Orders\Enums\FulfillmentState;
use Commerz\Sales\Orders\Enums\OrderState;
use Commerz\Sales\Orders\Enums\PaymentState;
use Commerz\Sales\Orders\Events\OrderCancelled;
use Commerz\Sales\Orders\Events\PaymentRequiresRefund;
use Commerz\Sales\Orders\Exceptions\OrderNotCancellable;
use Commerz\Sales\Orders\Exceptions\OrderNotFound;
use Commerz\Schema\TableNames;
use Commerz\Testkit\Commerce;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

require_once __DIR__.'/../Helpers.php';

uses(RefreshDatabase::class, SlsEnvironment::class);

beforeEach(function (): void {
    $this->commerce = Commerce::fake();
    $this->commerce->sellables->add(slsSnapshot('fake:a', '100.00'), slsSnapshot('fake:svc', '50.00', SellableKind::Service));
    slsConfig(['checkout' => ['require_delivery' => false]]);
    $this->web = slsChannel();
});

/** @return list<array<string, mixed>> payloads of the outbox messages of the type, oldest first (jsonb: keys unordered) */
function cnlOutbox(string $type): array
{
    return DB::table(app(TableNames::class)->table('outbox_messages'))->where('type', $type)->orderBy('id')->get()
        ->map(static fn (object $message): array => json_decode($message->payload, true))->all();
}

it('cancels every unit, releases the demand and gives the usage back as cancelled', function (): void {
    Event::fake([OrderCancelled::class, PaymentRequiresRefund::class]);
    $cart = slsCartWith($this->web, ['fake:a' => 2, 'fake:svc' => 1]);
    slsCarts()->enterCode(new EnterCode($cart->id, 'promo:p1'));
    $placed = slsPlace($cart);
    $demand = 'order:'.$placed->id->value;
    $manager = ActorRef::of('user', '5');

    $order = slsOrders()->cancel(new CancelOrder($placed->id, CancelReason::Customer, $manager));

    expect([$order->state, $order->cancelReason, $order->paymentState, $order->fulfillmentState])
        ->toBe([OrderState::Cancelled, CancelReason::Customer, PaymentState::Unpaid, FulfillmentState::Unfulfilled])
        ->and($order->cancelledAt)->not->toBeNull()
        ->and($order->amountDue->isZero())->toBeTrue()
        ->and(array_map(static fn (OrderLineView $line): array => [$line->cancelledQuantity, $line->outstanding()], $order->lines))
        ->toBe([[2, 0], [1, 0]])
        ->and($this->commerce->stock->released)->toEqual([new ReleaseReservation($demand)])
        ->and($this->commerce->stock->held($demand))->toBe([])
        ->and($this->commerce->promotions->restored)->toEqual([new RestorePromotionUsage($demand, RestoreReason::Cancelled)])
        ->and($this->commerce->promotions->uses('p1'))->toBe(0)
        ->and(cnlOutbox('sales.order_cancelled'))->toEqual([[
            'order_id' => $order->id->value,
            'number' => $order->number,
            'reason' => 'customer',
            'amount_due_minor' => 0,
            'captured_minor' => 0,
            'refunded_minor' => 0,
            'currency' => 'RUB',
        ]])
        ->and(cnlOutbox('sales.payment_requires_refund'))->toBe([])
        ->and(cnlOutbox('sales.payment_state_changed'))->toBe([])
        ->and(array_map(static fn (OrderEventView $event): array => [$event->type, $event->actor === null ? null : (string) $event->actor, $event->data], array_slice(slsOrders()->history($order->id), 1)))
        ->toEqual([['cancelled', 'user:5', ['reason' => 'customer']]]);
    Event::assertDispatched(OrderCancelled::class, static fn (OrderCancelled $event): bool => $event->order->equals($order->id) && $event->reason === CancelReason::Customer);
    Event::assertNotDispatched(PaymentRequiresRefund::class);
});

it('touches no stock for an order of services only', function (): void {
    $order = slsOrders()->cancel(new CancelOrder(slsPlace(slsCartWith($this->web, ['fake:svc' => 2], address: false))->id, CancelReason::Operator));

    expect($order->state)->toBe(OrderState::Cancelled)
        ->and($this->commerce->stock->released)->toBe([]);
});

it('changes nothing when the order is cancelled again', function (): void {
    $placed = slsPlace(slsCartWith($this->web, ['fake:a' => 1]));
    $first = slsOrders()->cancel(new CancelOrder($placed->id, CancelReason::Customer));

    $second = slsOrders()->cancel(new CancelOrder($placed->id, CancelReason::Fraud));

    expect($second)->toEqual($first)
        ->and($second->cancelReason)->toBe(CancelReason::Customer)
        ->and($this->commerce->stock->released)->toHaveCount(1)
        ->and($this->commerce->promotions->restored)->toBe([])
        ->and(cnlOutbox('sales.order_cancelled'))->toHaveCount(1)
        ->and(array_map(static fn (OrderEventView $event): string => $event->type, slsOrders()->history($placed->id)))->toBe(['placed', 'cancelled']);
});

it('refuses a completed order', function (): void {
    $placed = slsPlace(slsCartWith($this->web, ['fake:a' => 1]));
    slsLedger()->fulfill($placed->id, new LineQuantity(slsLine($placed, 'fake:a'), 1));
    slsOrderPayment($placed, 'captured', 10000, capturedMinor: 10000);
    expect(slsRefresh($placed)->state)->toBe(OrderState::Completed);

    try {
        slsOrders()->cancel(new CancelOrder($placed->id, CancelReason::Customer));
        $this->fail('cancelled a completed order');
    } catch (OrderNotCancellable $e) {
        expect([$e->reason, $e->code()])->toBe(['completed', 'sales.order_not_cancellable']);
    }
});

it('refuses an order with fulfilled units and changes nothing', function (): void {
    $placed = slsPlace(slsCartWith($this->web, ['fake:a' => 2, 'fake:svc' => 1]));
    slsLedger()->fulfill($placed->id, new LineQuantity(slsLine($placed, 'fake:a'), 1));

    expect(fn () => slsOrders()->cancel(new CancelOrder($placed->id, CancelReason::Customer)))
        ->toThrow(OrderNotCancellable::class, 'fulfilled')
        ->and(slsOrders()->find($placed->id)->lines[1]->cancelledQuantity)->toBe(0)
        ->and(slsOrders()->find($placed->id)->state)->toBe(OrderState::Placed)
        ->and($this->commerce->stock->released)->toBe([]);
});

it('refuses an unknown order', function (): void {
    expect(fn () => slsOrders()->cancel(new CancelOrder(OrderId::generate(), CancelReason::Customer)))->toThrow(OrderNotFound::class);
});

it('announces the refund a paid order needs, and refunds nothing', function (): void {
    Event::fake([PaymentRequiresRefund::class]);
    $placed = slsPlace(slsCartWith($this->web, ['fake:a' => 2]));
    slsOrderPayment($placed, 'captured', 20000, capturedMinor: 20000);
    slsOrderPayment($placed, 'failed', 20000);

    $order = slsOrders()->cancel(new CancelOrder($placed->id, CancelReason::OutOfStock));

    expect([$order->state, $order->paymentState])->toBe([OrderState::Cancelled, PaymentState::Paid])
        ->and($order->captured)->toEqual(Money::of('200.00', 'RUB'))
        ->and($order->refunded->isZero())->toBeTrue()
        ->and(cnlOutbox('sales.payment_requires_refund'))->toEqual([[
            'order_id' => $order->id->value,
            'number' => $order->number,
            'amount_minor' => 20000,
            'currency' => 'RUB',
        ]])
        ->and(cnlOutbox('sales.payment_state_changed'))->toHaveCount(1)
        ->and(cnlOutbox('sales.payment_state_changed')[0])->toMatchArray(['from' => 'unpaid', 'to' => 'paid', 'captured_minor' => 20000]);
    Event::assertDispatched(PaymentRequiresRefund::class, static fn (PaymentRequiresRefund $event): bool => $event->order->equals($order->id) && $event->amount->equals(Money::of('200.00', 'RUB')));
});
