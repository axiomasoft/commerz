<?php

declare(strict_types=1);

use Commerz\Errors\IdempotencyConflict;
use Commerz\Errors\InvalidCommand;
use Commerz\Money\Money;
use Commerz\Operations\Contracts\Transactions;
use Commerz\Ports\Promotions\Data\RestorePromotionUsage;
use Commerz\Ports\Promotions\Enums\RestoreReason;
use Commerz\Sales\Carts\Data\EnterCode;
use Commerz\Sales\Common\Data\LineQuantity;
use Commerz\Sales\Common\Exceptions\OperationInsideTransaction;
use Commerz\Sales\Facades\Sales as SalesFacade;
use Commerz\Sales\Fulfillments\Data\CreateFulfillment;
use Commerz\Sales\Gateways\Exceptions\InvalidGatewayResult;
use Commerz\Sales\Gateways\Exceptions\PaymentDeclined;
use Commerz\Sales\Orders\Data\OrderId;
use Commerz\Sales\Orders\Enums\PaymentState;
use Commerz\Sales\Orders\Exceptions\OrderNotFound;
use Commerz\Sales\Payments\Data\PaymentId;
use Commerz\Sales\Payments\Exceptions\PaymentNotFound;
use Commerz\Sales\Refunds\Contracts\Refunds;
use Commerz\Sales\Refunds\Data\IssueRefund;
use Commerz\Sales\Refunds\Data\ListRefunds;
use Commerz\Sales\Refunds\Data\RefundId;
use Commerz\Sales\Refunds\Enums\RefundStatus;
use Commerz\Sales\Refunds\Events\RefundStatusChanged;
use Commerz\Sales\Refunds\Exceptions\RefundExceedsBalance;
use Commerz\Sales\Refunds\Exceptions\RefundOutcomeUnknown;
use Commerz\Sales\Returns\Data\RequestReturn;
use Commerz\Sales\Returns\Data\ReturnId;
use Commerz\Sales\Returns\Exceptions\ReturnNotFound;
use Commerz\Sales\Sales;
use Commerz\Sales\Tests\Fixtures\RecordingGateway;
use Commerz\Testkit\Commerce;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

require_once __DIR__.'/Helpers.php';

uses(SlsEnvironment::class);

// Refunds::issue (sales-design#returns-refunds, REF-01): Tx A checks what the payment still holds and writes the
// attempt, the gateway is called outside any transaction, and its answer is written fenced by the attempt. Money only:
// the stock is never touched.

beforeEach(function (): void {
    slsCommitted();
    $this->commerce = Commerce::fake();
    $this->commerce->sellables->add(slsSnapshot('fake:a', '100.00'));
    slsConfig(['checkout' => ['require_delivery' => false]]);
    $this->web = slsChannel();
    $this->order = slsPlace(slsCartWith($this->web, ['fake:a' => 2]));
});

afterEach(fn () => slsTruncate());

it('refunds a manual payment in full: succeeded, counted on the payment and the order', function (): void {
    Event::fake([RefundStatusChanged::class]);
    $payment = rfCaptured($this->order);

    $refund = rfIssue($this->order, '200.00', reason: 'damaged');
    $order = slsOrders()->find($this->order->id);

    expect([$refund->status, $refund->payment, $refund->gateway, $refund->amount, $refund->attempts, $refund->reason])
        ->toEqual([RefundStatus::Succeeded, $payment->id, 'manual', Money::of('200.00', 'RUB'), 1, 'damaged'])
        ->and($refund->reference)->toBe('refund:'.$refund->id->value)
        ->and([$refund->externalId, $refund->return, $refund->errorCode])->toBe([null, null, null])
        ->and(slsPayments()->find($payment->id)->refunded)->toEqual(Money::of('200.00', 'RUB'))
        ->and([$order->paymentState, $order->refunded])->toEqual([PaymentState::Refunded, Money::of('200.00', 'RUB')])
        ->and(array_slice(rfHistory($this->order), -3))->toBe(['refund_issued', 'refund_status_changed', 'payment_state_changed'])
        ->and(rfOutbox('sales.refund_succeeded'))->toEqual([[
            'order_id' => $this->order->id->value,
            'number' => $this->order->number,
            'refund_id' => $refund->id->value,
            'payment_id' => $payment->id->value,
            'return_id' => null,
            'amount_minor' => 20000,
            'currency' => 'RUB',
        ]])
        ->and(slsRefunds()->find($refund->id))->toEqual($refund)
        ->and(slsRefunds()->list(new ListRefunds($this->order->id))->items)->toEqual([$refund])
        ->and(slsRefunds()->list(new ListRefunds(status: RefundStatus::Failed))->items)->toBe([]);

    Event::assertDispatched(RefundStatusChanged::class, static fn (RefundStatusChanged $event): bool => $event->refund->equals($refund->id) && $event->status === RefundStatus::Succeeded);
});

it('refunds part of a payment and leaves the order partially refunded', function (): void {
    $payment = rfCaptured($this->order);

    rfIssue($this->order, '50.00');
    rfIssue($this->order, '30.00');

    expect(slsPayments()->find($payment->id)->refunded)->toEqual(Money::of('80.00', 'RUB'))
        ->and(slsOrders()->find($this->order->id)->paymentState)->toBe(PaymentState::PartiallyRefunded);

    expect(fn () => rfIssue($this->order, '120.01'))->toThrow(function (RefundExceedsBalance $e): void {
        expect([$e->requested, $e->refundable])->toEqual([Money::of('120.01', 'RUB'), Money::of('120.00', 'RUB')])
            ->and($e->code())->toBe('sales.refund_exceeds_balance');
    });
});

it('takes the oldest captured payment that still holds the amount, or the one named', function (): void {
    $first = rfCaptured($this->order, '120.00');
    $second = rfCaptured($this->order, '80.00');

    $a = rfIssue($this->order, '100.00');
    $b = rfIssue($this->order, '50.00');
    $c = rfIssue($this->order, '20.00', $first);
    $d = rfIssue($this->order, '10.00', $second);

    expect([$a->payment, $b->payment, $c->payment, $d->payment])->toEqual([$first->id, $second->id, $first->id, $second->id])
        ->and(slsPayments()->find($first->id)->refunded)->toEqual(Money::of('120.00', 'RUB'))
        ->and(slsPayments()->find($second->id)->refunded)->toEqual(Money::of('60.00', 'RUB'));

    // No single payment holds 30.00 any more: 0 and 20.00 are left.
    expect(fn () => rfIssue($this->order, '30.00'))->toThrow(function (RefundExceedsBalance $e): void {
        expect($e->refundable)->toEqual(Money::of('20.00', 'RUB'));
    });
});

it('refuses what no payment holds, another currency, and a payment, return or order that is not the order', function (): void {
    expect(fn () => rfIssue($this->order, '1.00'))->toThrow(function (RefundExceedsBalance $e): void {
        expect($e->refundable)->toEqual(Money::of('0.00', 'RUB'));
    });

    rfCaptured($this->order);
    $other = slsPlace(slsCartWith($this->web, ['fake:a' => 1]));
    $foreign = rfCaptured($other);

    expect(fn () => rfIssue($this->order, '10.00', $foreign))->toThrow(PaymentNotFound::class)
        ->and(fn () => slsRefunds()->issue(new IssueRefund($this->order->id, Money::of('10.00', 'RUB'), PaymentId::generate())))->toThrow(PaymentNotFound::class)
        ->and(fn () => rfIssue($this->order, '10.00', return: ReturnId::generate()))->toThrow(ReturnNotFound::class)
        ->and(fn () => slsRefunds()->issue(new IssueRefund(OrderId::generate(), Money::of('10.00', 'RUB'))))->toThrow(OrderNotFound::class)
        ->and(fn () => slsRefunds()->issue(new IssueRefund($this->order->id, Money::of('10.00', 'USD'))))
        ->toThrow(fn (InvalidCommand $e) => expect($e->code())->toBe('sales.currency_mismatch'))
        ->and(fn () => new IssueRefund($this->order->id, Money::of('0.00', 'RUB')))
        ->toThrow(fn (InvalidCommand $e) => expect($e->code())->toBe('sales.invalid_refund'))
        ->and(slsRefunds()->list(new ListRefunds)->items)->toBe([]);
});

it('ties the refund to a return of the order', function (): void {
    rfCaptured($this->order);
    $line = slsLine($this->order, 'fake:a');
    slsFulfillments()->create(new CreateFulfillment($this->order->id, [new LineQuantity($line, 2)]));
    $return = slsReturns()->request(new RequestReturn($this->order->id, [new LineQuantity($line, 1)]));

    $refund = rfIssue($this->order, '100.00', return: $return->id);

    expect($refund->return)->toEqual($return->id)
        ->and(rfOutbox('sales.refund_succeeded')[0]['return_id'])->toBe($return->id->value);
});

it('fails a declined refund and lets the amount be refunded anew', function (): void {
    $payment = rfRecorded($this->order);
    RecordingGateway::scenario(['refund' => 'decline']);

    expect(fn () => rfIssue($this->order, '200.00'))->toThrow(PaymentDeclined::class);

    $failed = slsRefunds()->list(new ListRefunds($this->order->id))->items[0];
    RecordingGateway::scenario([]);
    $again = rfIssue($this->order, '200.00');

    expect([$failed->status, $failed->errorCode])->toBe([RefundStatus::Failed, 'sales.payment_declined'])
        ->and([$again->status, $again->externalId])->toBe([RefundStatus::Succeeded, 'ext-'.$again->reference])
        ->and(slsPayments()->find($payment->id)->refunded)->toEqual(Money::of('200.00', 'RUB'))
        ->and(RecordingGateway::calls('refund'))->toBe(['refund '.$failed->reference, 'refund '.$again->reference]);
});

it('leaves a refund whose answer was lost unknown, holding its amount', function (): void {
    $payment = rfRecorded($this->order);
    RecordingGateway::scenario(['refund' => 'timeout']);

    expect(fn () => rfIssue($this->order, '200.00'))->toThrow(function (RefundOutcomeUnknown $e): void {
        expect($e->code())->toBe('sales.refund_outcome_unknown')
            ->and(slsRefunds()->find(new RefundId($e->refund))->status)->toBe(RefundStatus::Unknown);
    });

    expect(fn () => rfIssue($this->order, '0.01'))->toThrow(RefundExceedsBalance::class)
        ->and(slsPayments()->find($payment->id)->refunded->isZero())->toBeTrue()
        ->and(slsOrders()->find($this->order->id)->paymentState)->toBe(PaymentState::Paid);
});

it('leaves a refund unknown when the gateway answers against its contract', function (): void {
    rfRecorded($this->order);
    RecordingGateway::scenario(['refund' => 'invalid']);

    expect(fn () => rfIssue($this->order, '200.00'))->toThrow(InvalidGatewayResult::class)
        ->and(slsRefunds()->list(new ListRefunds($this->order->id))->items[0]->status)->toBe(RefundStatus::Unknown);
});

it('keeps a refund the provider is still working on pending, counting nothing yet', function (): void {
    $payment = rfRecorded($this->order);
    RecordingGateway::scenario(['refund' => 'pending']);

    $refund = rfIssue($this->order, '200.00');

    expect([$refund->status, $refund->externalId])->toBe([RefundStatus::Pending, 'ext-'.$refund->reference])
        ->and(slsPayments()->find($payment->id)->refunded->isZero())->toBeTrue()
        ->and(slsOrders()->find($this->order->id)->paymentState)->toBe(PaymentState::Paid)
        ->and(rfOutbox('sales.refund_succeeded'))->toBe([]);
});

it('refuses to run inside a transaction and writes nothing', function (): void {
    rfCaptured($this->order);

    expect(fn () => app(Transactions::class)->run(fn () => rfIssue($this->order, '10.00')))
        ->toThrow(fn (OperationInsideTransaction $e) => expect($e->code())->toBe('sales.operation_inside_transaction'))
        ->and(DB::table('cmz_refunds')->count())->toBe(0);
});

it('returns the refund of a repeated key without asking the gateway again', function (): void {
    rfRecorded($this->order);

    $first = rfIssue($this->order, '50.00', key: 'refund-1');
    $again = rfIssue($this->order, '50.00', key: 'refund-1');

    expect($again)->toEqual($first)
        ->and(RecordingGateway::calls('refund'))->toBe(['refund '.$first->reference])
        ->and(fn () => rfIssue($this->order, '60.00', key: 'refund-1'))->toThrow(IdempotencyConflict::class);
});

it('gives the promotion usage back as refunded when the order is refunded in full, and only then', function (): void {
    $cart = slsCartWith($this->web, ['fake:a' => 2]);
    slsCarts()->enterCode(new EnterCode($cart->id, 'promo:p1'));
    $order = slsPlace($cart);
    rfCaptured($order);

    rfIssue($order, '100.00');
    $partial = $this->commerce->promotions->restored;
    rfIssue($order, '90.00');

    expect($order->total)->toEqual(Money::of('190.00', 'RUB'))
        ->and($partial)->toBe([])
        ->and($this->commerce->promotions->restored)->toEqual([new RestorePromotionUsage('order:'.$order->id->value, RestoreReason::Refunded)])
        ->and($this->commerce->promotions->uses('p1'))->toBe(0)
        ->and(slsOrders()->find($order->id)->paymentState)->toBe(PaymentState::Refunded);
});

it('never touches the stock', function (): void {
    rfCaptured($this->order);
    $stock = $this->commerce->stock;
    $before = [$stock->reserved, $stock->released, $stock->consumed, $stock->restocked];

    rfIssue($this->order, '200.00');

    expect([$stock->reserved, $stock->released, $stock->consumed, $stock->restocked])->toEqual($before)
        ->and($stock->restocked)->toBe([]);
});

it('hands the refunds out from the root and the facade', function (): void {
    expect(app(Sales::class)->refunds())->toBe(app(Refunds::class))
        ->and(SalesFacade::refunds())->toBe(app(Refunds::class));
});
