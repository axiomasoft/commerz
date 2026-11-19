<?php

declare(strict_types=1);

use Commerz\Identity\SellableRef;
use Commerz\Money\Money;
use Commerz\Sales\Carts\Data\EnterCode;
use Commerz\Sales\Carts\Data\SelectDelivery;
use Commerz\Sales\Common\Data\LineQuantity;
use Commerz\Sales\Facades\Sales;
use Commerz\Sales\Fulfillments\Data\CreateFulfillment;
use Commerz\Sales\Fulfillments\Data\MarkFulfillmentDelivered;
use Commerz\Sales\Fulfillments\Data\ShipFulfillment;
use Commerz\Sales\Orders\Enums\FulfillmentState;
use Commerz\Sales\Orders\Enums\OrderState;
use Commerz\Sales\Orders\Enums\PaymentState;
use Commerz\Sales\Payments\Data\InitiatePayment;
use Commerz\Sales\Payments\Data\RecordPaymentEvent;
use Commerz\Sales\Payments\Enums\PaymentEventType;
use Commerz\Sales\Refunds\Data\IssueRefund;
use Commerz\Sales\Refunds\Enums\RefundStatus;
use Commerz\Sales\Returns\Data\ReceiveReturn;
use Commerz\Sales\Returns\Data\RequestReturn;
use Commerz\Schema\TableNames;
use Commerz\Testkit\Commerce;
use Commerz\Testkit\Fakes\FakeStock;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Helpers.php';

uses(SlsEnvironment::class);

beforeEach(fn () => slsCommitted());

afterEach(fn () => slsTruncate());

// A shop on the fakes: a limited stock and promotion, a promotion and a delivery priced by fixture stages. The order is
// placed, paid by a manual event, shipped, delivered, partly returned with restock and partly refunded, using the public
// API and the ports of the core only.

it('sells goods from cart to partial refund and leaves the outbox in order', function (): void {
    $commerce = Commerce::fake();
    $commerce->sellables->add(slsSnapshot('fake:a', '100.00'), slsSnapshot('fake:b', '30.00'));
    $commerce->stock->limit(SellableRef::fromString('fake:a'), 5)->limit(SellableRef::fromString('fake:b'), 2);
    $commerce->promotions->limit('p1', 1);
    $channel = slsChannel();

    $cart = slsCartWith($channel, ['fake:a' => 3, 'fake:b' => 1]);
    Sales::carts()->enterCode(new EnterCode($cart->id, 'promo:p1'));
    Sales::carts()->selectDelivery(new SelectDelivery($cart->id, 'flat:300.00'));
    $quote = Sales::carts()->quote($cart->id);

    // 3 × 100 + 30 − 10 promotion + 300 delivery.
    expect($quote->total)->toEqual(Money::of('620.00', 'RUB'));

    $order = slsPlace($cart, 'shop-1');
    $demand = 'order:'.$order->id->value;

    expect($order->total)->toEqual($quote->total)
        ->and($commerce->stock->held($demand))->toBe(['fake:a' => 3, 'fake:b' => 1])
        ->and($commerce->stock->available(SellableRef::fromString('fake:a')))->toBe(2)
        ->and($commerce->promotions->uses('p1'))->toBe(1);

    $payment = Sales::payments()->initiate(new InitiatePayment($order->id, 'manual'));
    Sales::payments()->recordEvent(new RecordPaymentEvent('manual', 'bank-tx-42', PaymentEventType::Captured, paymentReference: $payment->reference));

    expect(Sales::orders()->find($order->id)->paymentState)->toBe(PaymentState::Paid);

    $a = slsLine($order, 'fake:a');
    $b = slsLine($order, 'fake:b');
    $fulfillment = Sales::fulfillments()->create(new CreateFulfillment($order->id, [new LineQuantity($a, 3), new LineQuantity($b, 1)], locationCode: FakeStock::LOCATION));
    Sales::fulfillments()->ship(new ShipFulfillment($fulfillment->id, 'manual', 'RB123'));
    Sales::fulfillments()->markDelivered(new MarkFulfillmentDelivered($fulfillment->id));
    $delivered = Sales::orders()->find($order->id);

    expect($commerce->stock->held($demand))->toBe([])
        ->and($delivered->fulfillmentState)->toBe(FulfillmentState::Fulfilled)
        ->and($delivered->state)->toBe(OrderState::Completed);

    $return = Sales::returns()->request(new RequestReturn($order->id, [new LineQuantity($a, 1)], reason: 'defect'));
    Sales::returns()->receive(new ReceiveReturn($return->id, [new LineQuantity($a, 1)], FakeStock::LOCATION));
    $refund = Sales::refunds()->issue(new IssueRefund($order->id, Money::of('100.00', 'RUB'), return: $return->id));
    $final = Sales::orders()->find($order->id);

    expect($refund->status)->toBe(RefundStatus::Succeeded)
        ->and($commerce->stock->restocked)->toHaveCount(1)
        ->and($commerce->stock->onHand(SellableRef::fromString('fake:a')))->toBe(3)
        ->and($final->line($a)?->returnedQuantity)->toBe(1)
        ->and($final->refunded)->toEqual(Money::of('100.00', 'RUB'))
        ->and($final->paymentState)->toBe(PaymentState::PartiallyRefunded)
        // A partial refund hands no promotion usage back.
        ->and($commerce->promotions->uses('p1'))->toBe(1)
        ->and(DB::table(app(TableNames::class)->table('outbox_messages'))->orderBy('id')->pluck('type')->all())->toBe([
            'sales.order_placed',
            'sales.payment_state_changed',
            'sales.order_completed',
            'sales.fulfillment_shipped',
            'sales.return_received',
            'sales.payment_state_changed',
            'sales.refund_succeeded',
        ]);
});
