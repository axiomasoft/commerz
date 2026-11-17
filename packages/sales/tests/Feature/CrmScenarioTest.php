<?php

declare(strict_types=1);

use Commerz\Identity\ActorRef;
use Commerz\Identity\SellableRef;
use Commerz\Money\Money;
use Commerz\Ports\Sellables\Enums\SellableKind;
use Commerz\Sales\Carts\Enums\CartKind;
use Commerz\Sales\Checkout\Data\PlaceOrder;
use Commerz\Sales\Common\Data\LineQuantity;
use Commerz\Sales\Facades\Sales;
use Commerz\Sales\Fulfillments\Data\CreateFulfillment;
use Commerz\Sales\Orders\Data\DraftLine;
use Commerz\Sales\Orders\Data\DraftOrder;
use Commerz\Sales\Orders\Enums\FulfillmentState;
use Commerz\Sales\Orders\Enums\OrderState;
use Commerz\Sales\Orders\Enums\PaymentState;
use Commerz\Sales\Payments\Data\InitiatePayment;
use Commerz\Sales\Payments\Data\RecordPaymentEvent;
use Commerz\Sales\Payments\Enums\PaymentEventType;
use Commerz\Testkit\Commerce;
use Illuminate\Support\Facades\DB;
use Packman\Schema\Data\Compiler\TableNames;

require_once __DIR__.'/Helpers.php';

uses(SlsEnvironment::class);

beforeEach(fn () => slsCommitted());

afterEach(fn () => slsTruncate());

// A CRM sells services without a shop: a manager drafts an order of a `crm.service` provider on the fakes, the
// customer pays by a manual event, the service is rendered and the order completes. No warehouse is ever called.

it('runs a CRM order from draft to completed on fakes, without any stock call', function (): void {
    $commerce = Commerce::fake();
    $commerce->sellables->add(slsSnapshot('crm.service:77', '1500.00', SellableKind::Service, 'Consultation'));
    $channel = slsChannel('crm', 'CRM-');
    $manager = ActorRef::of('user', '5');
    $service = SellableRef::of('crm.service', '77');

    $draft = Sales::orders()->draft(new DraftOrder(slsContext($channel), [new DraftLine($service, 2)], actor: $manager));
    $quote = Sales::carts()->quote($draft->id);
    $order = Sales::checkout()->place(new PlaceOrder($draft->id, $quote->total, actor: $manager));

    expect($draft->kind)->toBe(CartKind::Draft)
        ->and($order->origin)->toBe(CartKind::Draft)
        ->and($order->number)->toStartWith('CRM-')
        ->and($order->total)->toEqual(Money::of('3000.00', 'RUB'))
        ->and([$order->state, $order->paymentState, $order->fulfillmentState])
        ->toBe([OrderState::Placed, PaymentState::Unpaid, FulfillmentState::Unfulfilled])
        ->and($order->lines[0]->requiresShipping)->toBeFalse()
        ->and($order->delivery)->toBeNull();

    $payment = Sales::payments()->initiate(new InitiatePayment($order->id, 'manual'));
    Sales::payments()->recordEvent(new RecordPaymentEvent('manual', 'bank-tx-1', PaymentEventType::Captured, paymentReference: $payment->reference));
    $paid = Sales::orders()->find($order->id);

    expect($paid->paymentState)->toBe(PaymentState::Paid)
        ->and($paid->captured)->toEqual($order->total)
        ->and($paid->state)->toBe(OrderState::Placed);

    Sales::fulfillments()->create(new CreateFulfillment($order->id, [new LineQuantity(slsLine($order, 'crm.service:77'), 2)], locationCode: 'office'));
    $done = Sales::orders()->find($order->id);
    $outbox = DB::table(app(TableNames::class)->table('outbox_messages'))->orderBy('id')->pluck('type')->all();

    expect([$done->state, $done->paymentState, $done->fulfillmentState])
        ->toBe([OrderState::Completed, PaymentState::Paid, FulfillmentState::Fulfilled])
        ->and($done->completedAt)->not->toBeNull()
        ->and($outbox)->toContain('sales.order_placed', 'sales.payment_state_changed', 'sales.order_completed')
        ->and($commerce->stock->reserved)->toBe([])
        ->and($commerce->stock->consumed)->toBe([])
        ->and($commerce->stock->released)->toBe([])
        ->and($commerce->stock->restocked)->toBe([]);
});
