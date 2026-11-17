<?php

declare(strict_types=1);

use Commerz\Errors\IdempotencyConflict;
use Commerz\Errors\InvalidCommand;
use Commerz\Money\Money;
use Commerz\Operations\Contracts\Transactions;
use Commerz\Sales\Common\Data\LineQuantity;
use Commerz\Sales\Common\Exceptions\OperationInsideTransaction;
use Commerz\Sales\Gateways\Data\GatewayPaymentRequest;
use Commerz\Sales\Gateways\Exceptions\InvalidGatewayResult;
use Commerz\Sales\Gateways\Exceptions\PaymentDeclined;
use Commerz\Sales\Orders\Data\CancelOrder;
use Commerz\Sales\Orders\Data\OrderEventView;
use Commerz\Sales\Orders\Enums\CancelReason;
use Commerz\Sales\Orders\Enums\OrderState;
use Commerz\Sales\Orders\Enums\PaymentState;
use Commerz\Sales\Payments\Data\ListPayments;
use Commerz\Sales\Payments\Data\RecordPaymentEvent;
use Commerz\Sales\Payments\Enums\PaymentEventType;
use Commerz\Sales\Payments\Enums\PaymentStatus;
use Commerz\Sales\Payments\Events\PaymentStatusChanged;
use Commerz\Sales\Payments\Exceptions\OrderNotPayable;
use Commerz\Sales\Payments\Exceptions\PaymentExceedsBalance;
use Commerz\Sales\Payments\Exceptions\PaymentOutcomeUnknown;
use Commerz\Sales\Tests\Fixtures\RecordingGateway;
use Commerz\Testkit\Commerce;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Packman\Plugins\Exceptions\Registry\UnsupportedContribution;

require_once __DIR__.'/../Helpers.php';

uses(SlsEnvironment::class);

// Payments::initiate (sales-design#payments, PAY-02): Tx A writes the attempt, the gateway is called outside any
// transaction, and its answer is written fenced by the attempt.

beforeEach(function (): void {
    slsCommitted();
    $this->commerce = Commerce::fake();
    $this->commerce->sellables->add(slsSnapshot('fake:a', '100.00'));
    slsConfig(['checkout' => ['require_delivery' => false]]);
    $this->order = slsPlace(slsCartWith(slsChannel(), ['fake:a' => 2]));
});

afterEach(fn () => slsTruncate());

it('starts a manual payment of the balance as pending without the network', function (): void {
    Event::fake([PaymentStatusChanged::class]);

    $payment = slsPay($this->order);
    $order = slsOrders()->find($this->order->id);

    expect([$payment->status, $payment->gateway, $payment->amount, $payment->attempts])
        ->toEqual([PaymentStatus::Pending, 'manual', $this->order->amountDue, 1])
        ->and($payment->reference)->toBe('payment:'.$payment->id->value)
        ->and([$payment->externalId, $payment->meta, $payment->errorCode])->toBe([null, ['instructions' => 'offline'], null])
        ->and($order->paymentState)->toBe(PaymentState::Unpaid)
        ->and(array_map(static fn (OrderEventView $event): string => $event->type, slsOrders()->history($this->order->id)))
        ->toBe(['placed', 'payment_initiated', 'payment_status_changed'])
        ->and(slsPayments()->find($payment->id))->toEqual($payment)
        ->and(slsPayments()->list(new ListPayments($this->order->id))->items)->toEqual([$payment]);

    Event::assertDispatched(PaymentStatusChanged::class, static fn (PaymentStatusChanged $event): bool => $event->status === PaymentStatus::Pending);
});

it('records what the gateway answered and derives the payment state of the order', function (string $status, PaymentStatus $payment, PaymentState $order): void {
    $view = slsPay($this->order, 'recording', meta: ['status' => $status]);

    expect([$view->status, $view->externalId, $view->meta])->toBe([$payment, 'ext-'.$view->reference, ['status' => $status, 'gateway' => 'recording']])
        ->and(slsOrders()->find($this->order->id)->paymentState)->toBe($order)
        ->and(RecordingGateway::calls())->toBe(['initiate '.$view->reference]);
})->with([
    'authorized' => ['authorized', PaymentStatus::Authorized, PaymentState::Authorized],
    'captured' => ['captured', PaymentStatus::Captured, PaymentState::Paid],
]);

it('keeps the action url of a payment that requires one', function (): void {
    $payment = slsPay($this->order, 'recording', meta: ['status' => 'requires_action']);

    expect([$payment->status, $payment->actionUrl])->toBe([PaymentStatus::RequiresAction, 'https://pay.example/3ds/'.$payment->reference]);
});

it('completes a fulfilled order once its payment is captured', function (): void {
    slsLedger()->fulfill($this->order->id, new LineQuantity(slsLine($this->order, 'fake:a'), 2));
    slsRefresh($this->order);

    slsPay($this->order, 'recording', meta: ['status' => 'captured']);

    expect(slsOrders()->find($this->order->id))->state->toBe(OrderState::Completed)->paymentState->toBe(PaymentState::Paid);
});

it('refuses to run inside a transaction and writes nothing', function (): void {
    expect(fn () => app(Transactions::class)->run(fn () => slsPay($this->order)))->toThrow(OperationInsideTransaction::class)
        ->and(DB::table('cmz_payments')->count())->toBe(0);
});

it('refuses an unknown gateway before writing anything', function (): void {
    expect(fn () => slsPay($this->order, 'acme.pay'))->toThrow(UnsupportedContribution::class)
        ->and(DB::table('cmz_payments')->count())->toBe(0);
});

it('refuses a cancelled or completed order', function (): void {
    slsOrders()->cancel(new CancelOrder($this->order->id, CancelReason::Customer));

    expect(fn () => slsPay($this->order))->toThrow(OrderNotPayable::class, 'cancelled');

    $other = slsPlace(slsCartWith(slsChannel('shop', 'S-'), ['fake:a' => 1]));
    slsLedger()->fulfill($other->id, new LineQuantity(slsLine($other, 'fake:a'), 1));
    slsOrderPayment($other, 'captured', $other->amountDue->amountMinor, $other->amountDue->amountMinor);
    expect(slsRefresh($other)->state)->toBe(OrderState::Completed)
        ->and(fn () => slsPay($other))->toThrow(OrderNotPayable::class, 'completed')
        ->and(DB::table('cmz_payments')->count())->toBe(1);
});

it('lets the payments under way take no more than the order owes', function (): void {
    $due = $this->order->amountDue;
    $half = Money::ofMinor(intdiv($due->amountMinor, 2), $due->currency);

    expect(fn () => slsPay($this->order, amount: $due->plus(Money::ofMinor(1, $due->currency))))->toThrow(PaymentExceedsBalance::class);

    $first = slsPay($this->order, amount: $half);
    $rest = slsPay($this->order);

    expect($rest->amount)->toEqual($due->minus($half))
        ->and(fn () => slsPay($this->order))->toThrow(PaymentExceedsBalance::class)
        ->and(fn () => slsPay($this->order, amount: Money::of('1.00', 'USD')))->toThrow(InvalidCommand::class, 'currency of the order')
        ->and(DB::table('cmz_payments')->count())->toBe(2)
        ->and($first->amount)->toEqual($half);

    try {
        slsPay($this->order, amount: Money::of('1.00', 'USD'));
    } catch (InvalidCommand $e) {
        expect($e->code())->toBe('sales.currency_mismatch');
    }
});

it('fails a declined attempt and lets a new one start', function (): void {
    expect(fn () => slsPay($this->order, 'recording', meta: ['fail' => 'decline']))->toThrow(PaymentDeclined::class);

    $declined = slsPayments()->list(new ListPayments($this->order->id))->items[0];
    $again = slsPay($this->order, 'recording');

    expect([$declined->status, $declined->errorCode])->toBe([PaymentStatus::Failed, 'sales.payment_declined'])
        ->and([$again->status, $again->amount])->toEqual([PaymentStatus::Pending, $this->order->amountDue]);
});

it('leaves an attempt unknown, never failed, when the gateway times out', function (): void {
    try {
        slsPay($this->order, 'recording', meta: ['fail' => 'timeout']);
        $this->fail('The timeout was swallowed.');
    } catch (PaymentOutcomeUnknown $e) {
        expect($e->getPrevious()?->getMessage())->toBe('The gateway timed out.')
            ->and($e->context())->toBe(['payment' => $e->payment, 'gateway' => 'recording'])
            ->and($e->code())->toBe('sales.payment_outcome_unknown');
    }

    $payment = slsPayments()->list(new ListPayments($this->order->id))->items[0];

    expect([$payment->status, $payment->errorCode, $payment->errorMessage])->toBe([PaymentStatus::Unknown, RuntimeException::class, 'The gateway timed out.'])
        // An unknown attempt may have taken the money: it still counts against the balance.
        ->and(fn () => slsPay($this->order))->toThrow(PaymentExceedsBalance::class);
});

it('leaves an attempt unknown when the gateway answers against its contract', function (): void {
    expect(fn () => slsPay($this->order, 'recording', meta: ['fail' => 'invalid']))->toThrow(InvalidGatewayResult::class, 'captured amount');

    $payment = slsPayments()->list(new ListPayments($this->order->id))->items[0];

    expect([$payment->status, $payment->errorCode, $payment->captured->amountMinor])->toBe([PaymentStatus::Unknown, 'sales.invalid_gateway_result', 0]);
});

it('returns the same payment for a repeat with the key, without calling the gateway again', function (): void {
    $first = slsPay($this->order, 'recording', key: 'pay-1');
    $again = slsPay($this->order, 'recording', key: 'pay-1');

    expect($again)->toEqual($first)
        ->and(RecordingGateway::calls('initiate'))->toHaveCount(1)
        ->and(DB::table('cmz_payments')->count())->toBe(1)
        ->and(fn () => slsPay($this->order, 'manual', key: 'pay-1'))->toThrow(IdempotencyConflict::class);
});

it('keeps the capture the gateway answered after a webhook moved the attempt on', function (PaymentEventType $webhook): void {
    RecordingGateway::during(static fn (GatewayPaymentRequest $request) => slsPayments()->recordEvent(
        new RecordPaymentEvent('recording', 'evt-early', $webhook, paymentReference: $request->reference),
    ));

    $payment = slsPay($this->order, 'recording', meta: ['status' => 'captured']);

    expect([$payment->status, $payment->captured])->toEqual([PaymentStatus::Captured, $this->order->amountDue])
        ->and(slsOrders()->find($this->order->id)->paymentState)->toBe(PaymentState::Paid)
        ->and(fn () => slsPay($this->order))->toThrow(PaymentExceedsBalance::class);
})->with([PaymentEventType::Failed, PaymentEventType::Authorized]);
