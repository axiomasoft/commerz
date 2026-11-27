<?php

declare(strict_types=1);

use Commerz\Errors\IdempotencyConflict;
use Commerz\Money\Money;
use Commerz\Sales\Common\Data\LineQuantity;
use Commerz\Sales\Orders\Data\CancelOrder;
use Commerz\Sales\Orders\Enums\CancelReason;
use Commerz\Sales\Orders\Enums\OrderState;
use Commerz\Sales\Orders\Enums\PaymentState;
use Commerz\Sales\Orders\Events\PaymentRequiresRefund;
use Commerz\Sales\Payments\Data\PaymentView;
use Commerz\Sales\Payments\Data\RecordPaymentEvent;
use Commerz\Sales\Payments\Enums\PaymentEventOutcome;
use Commerz\Sales\Payments\Enums\PaymentEventType;
use Commerz\Sales\Payments\Enums\PaymentStatus;
use Commerz\Sales\Payments\Exceptions\InvalidPaymentEvent;
use Commerz\Sales\Payments\Exceptions\PaymentNotFound;
use Commerz\Testkit\Commerce;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Assert;

require_once __DIR__.'/../Helpers.php';

uses(SlsEnvironment::class);

// Payments::recordEvent (PAY-01): one row per (gateway, external event id) in the inbox, claimed under the lock of the
// order before the payment moves; the payment only moves forward.

beforeEach(function (): void {
    slsCommitted();
    $this->commerce = Commerce::fake();
    $this->commerce->sellables->add(slsSnapshot('fake:a', '100.00'));
    slsConfig(['checkout' => ['require_delivery' => false]]);
    $this->order = slsPlace(slsCartWith(slsChannel(), ['fake:a' => 2]));
    $this->payment = slsPay($this->order);
});

afterEach(fn () => slsTruncate());

it('learns an external id from an ignored observation and resolves the later capture by it', function (): void {
    slsPayments()->recordEvent(new RecordPaymentEvent('manual', 'auth-first', PaymentEventType::Authorized, paymentReference: $this->payment->reference));
    $event = new RecordPaymentEvent('manual', 'auth-id', PaymentEventType::Authorized, paymentReference: $this->payment->reference, externalPaymentId: 'provider-123');
    $ignored = slsPayments()->recordEvent($event);
    $duplicate = slsPayments()->recordEvent($event);
    $capture = slsPayments()->recordEvent(new RecordPaymentEvent('manual', 'capture-by-id', PaymentEventType::Captured, externalPaymentId: 'provider-123'));

    expect([$ignored->outcome, $duplicate->outcome, $duplicate->duplicate])->toBe([PaymentEventOutcome::Ignored, PaymentEventOutcome::Ignored, true])
        ->and(slsPayments()->find($this->payment->id)->externalId)->toBe('provider-123')
        ->and([$capture->outcome, $capture->status])->toBe([PaymentEventOutcome::Applied, PaymentStatus::Captured])
        ->and(slsOrders()->find($this->order->id)->captured)->toEqual($this->order->amountDue);
});

function evCaptured(PaymentView $payment, string $id = 'bank-tx-42', ?Money $amount = null, string $gateway = 'manual'): RecordPaymentEvent
{
    return new RecordPaymentEvent($gateway, $id, PaymentEventType::Captured, paymentReference: $payment->reference, amount: $amount);
}

function evCount(string $table, string $type): int
{
    return DB::table($table)->where('type', $type)->count();
}

it('records the money of a manual payment and pays the order', function (): void {
    $result = slsPayments()->recordEvent(evCaptured($this->payment));
    $payment = slsPayments()->find($this->payment->id);
    $row = DB::table('cmz_payment_events')->first();

    if (! is_object($row)) {
        throw new RuntimeException('Expected a stored payment event.');
    }

    $stored = get_object_vars($row);

    expect([$result->payment->value, $result->duplicate, $result->outcome, $result->status])
        ->toBe([$this->payment->id->value, false, PaymentEventOutcome::Applied, PaymentStatus::Captured])
        ->and([$payment->status, $payment->captured])->toEqual([PaymentStatus::Captured, $this->order->amountDue])
        ->and(slsOrders()->find($this->order->id))->paymentState->toBe(PaymentState::Paid)->captured->toEqual($this->order->amountDue)
        ->and([$stored['gateway'] ?? null, $stored['external_event_id'] ?? null, $stored['type'] ?? null, $stored['outcome'] ?? null, $stored['payment_id'] ?? null])
        ->toBe(['manual', 'bank-tx-42', 'captured', 'applied', $this->payment->id->value]);
});

it('completes a fulfilled order with the captured money', function (): void {
    slsLedger()->fulfill($this->order->id, new LineQuantity(slsLine($this->order, 'fake:a'), 2));
    slsRefresh($this->order);

    slsPayments()->recordEvent(evCaptured($this->payment));

    expect(slsOrders()->find($this->order->id))->state->toBe(OrderState::Completed)->paymentState->toBe(PaymentState::Paid);
});

it('takes a repeated event as a duplicate and changes nothing', function (): void {
    slsPayments()->recordEvent(evCaptured($this->payment));
    $outbox = DB::table('cmz_outbox_messages')->count();

    $again = slsPayments()->recordEvent(evCaptured($this->payment));

    expect([$again->duplicate, $again->outcome, $again->status])->toBe([true, PaymentEventOutcome::Applied, PaymentStatus::Captured])
        ->and(slsPayments()->find($this->payment->id)->captured)->toEqual($this->order->amountDue)
        ->and(DB::table('cmz_payment_events')->count())->toBe(1)
        ->and(DB::table('cmz_outbox_messages')->count())->toBe($outbox);
});

it('refuses the same event id with another content', function (): void {
    $half = Money::ofMinor(intdiv($this->order->amountDue->amountMinor, 2), 'RUB');
    slsPayments()->recordEvent(evCaptured($this->payment, amount: $half));

    expect(fn () => slsPayments()->recordEvent(evCaptured($this->payment)))->toThrow(IdempotencyConflict::class)
        ->and(slsPayments()->find($this->payment->id)->captured)->toEqual($half);
});

it('keeps nothing of an event about a payment it does not know', function (): void {
    $unknown = new RecordPaymentEvent('manual', 'bank-tx-1', PaymentEventType::Captured, paymentReference: 'payment:nope');
    $foreign = evCaptured($this->payment, gateway: 'recording');

    expect(fn () => slsPayments()->recordEvent($unknown))->toThrow(PaymentNotFound::class)
        ->and(fn () => slsPayments()->recordEvent($foreign))->toThrow(PaymentNotFound::class)
        ->and(DB::table('cmz_payment_events')->count())->toBe(0);
});

it('refuses an event that does not fit its payment', function (Closure $event, string $reason): void {
    $command = $event($this->payment);

    if (! $command instanceof RecordPaymentEvent) {
        throw new RuntimeException('Expected a payment event.');
    }

    expect(fn () => slsPayments()->recordEvent($command))->toThrow(InvalidPaymentEvent::class, $reason)
        ->and(DB::table('cmz_payment_events')->count())->toBe(0)
        ->and(slsPayments()->find($this->payment->id)->status)->toBe(PaymentStatus::Pending);
})->with([
    'more than the payment' => [fn (PaymentView $payment) => evCaptured($payment, amount: $payment->amount->plus(Money::ofMinor(1, 'RUB'))), 'exceeds the payment'],
    'another currency' => [fn (PaymentView $payment) => evCaptured($payment, amount: Money::of('1.00', 'USD')), 'currency of the payment'],
]);

it('refuses a malformed event', function (Closure $event): void {
    try {
        $event();
        Assert::fail('The event was accepted.');
    } catch (InvalidPaymentEvent $e) {
        expect($e->code())->toBe('sales.invalid_payment_event');
    }
})->with([
    'no payment' => [fn () => new RecordPaymentEvent('manual', 'e-1', PaymentEventType::Captured)],
    'empty event id' => [fn () => new RecordPaymentEvent('manual', '', PaymentEventType::Captured, 'payment:x')],
    'long event id' => [fn () => new RecordPaymentEvent('manual', str_repeat('e', 192), PaymentEventType::Captured, 'payment:x')],
    'bad gateway' => [fn () => new RecordPaymentEvent('Manual', 'e-1', PaymentEventType::Captured, 'payment:x')],
    'zero amount' => [fn () => new RecordPaymentEvent('manual', 'e-1', PaymentEventType::Captured, 'payment:x', amount: Money::zero('RUB'))],
    'payload with a list' => [fn () => new ReflectionClass(RecordPaymentEvent::class)->newInstance('manual', 'e-1', PaymentEventType::Captured, 'payment:x', null, null, null, ['a' => [1]])],
]);

it('keeps an event that would move the payment back as ignored', function (): void {
    slsPayments()->recordEvent(evCaptured($this->payment));

    $late = slsPayments()->recordEvent(new RecordPaymentEvent('manual', 'bank-auth-1', PaymentEventType::Authorized, paymentReference: $this->payment->reference));

    expect([$late->duplicate, $late->outcome, $late->status])->toBe([false, PaymentEventOutcome::Ignored, PaymentStatus::Captured])
        ->and(DB::table('cmz_payment_events')->where('external_event_id', 'bank-auth-1')->value('outcome'))->toBe('ignored')
        ->and(slsPayments()->recordEvent(new RecordPaymentEvent('manual', 'bank-auth-1', PaymentEventType::Authorized, paymentReference: $this->payment->reference)))
        ->duplicate->toBeTrue()->outcome->toBe(PaymentEventOutcome::Ignored);
});

it('finds the payment by the provider id and learns that id from an event that carries both', function (): void {
    $recorded = slsPay(slsPlace(slsCartWith(slsChannel('shop', 'S-'), ['fake:a' => 1])), 'recording');

    $byExternal = slsPayments()->recordEvent(new RecordPaymentEvent('recording', 'evt-1', PaymentEventType::Authorized, externalPaymentId: $recorded->externalId));
    $both = slsPayments()->recordEvent(new RecordPaymentEvent('manual', 'evt-2', PaymentEventType::Captured, $this->payment->reference, 'bank-777'));

    expect([$byExternal->payment->value, $byExternal->status])->toBe([$recorded->id->value, PaymentStatus::Authorized])
        ->and(slsPayments()->find($this->payment->id)->externalId)->toBe('bank-777')
        ->and($both->status)->toBe(PaymentStatus::Captured)
        ->and(fn () => slsPayments()->recordEvent(new RecordPaymentEvent('manual', 'evt-3', PaymentEventType::Captured, $this->payment->reference, 'bank-778')))
        ->toThrow(InvalidPaymentEvent::class, 'differs');
});

it('keeps money captured after the cancellation and asks for a refund once', function (): void {
    Event::fake([PaymentRequiresRefund::class]);
    slsOrders()->cancel(new CancelOrder($this->order->id, CancelReason::Customer));

    $result = slsPayments()->recordEvent(evCaptured($this->payment));
    $order = slsOrders()->find($this->order->id);

    expect($result->outcome)->toBe(PaymentEventOutcome::Applied)
        ->and([$order->state, $order->paymentState])->toBe([OrderState::Cancelled, PaymentState::Paid])
        ->and(evCount('cmz_outbox_messages', 'sales.payment_requires_refund'))->toBe(1)
        ->and(evCount('cmz_order_events', 'payment_requires_refund'))->toBe(1);

    Event::assertDispatchedTimes(PaymentRequiresRefund::class, 1);
    Event::assertDispatched(PaymentRequiresRefund::class, fn (PaymentRequiresRefund $event): bool => $event->amount->equals($this->order->amountDue));
});
