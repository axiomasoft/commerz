<?php

declare(strict_types=1);

use Commerz\Identity\IdempotencyKey;
use Commerz\Sales\Orders\Data\CancelOrder;
use Commerz\Sales\Orders\Data\ExpireOrders;
use Commerz\Sales\Orders\Enums\CancelReason;
use Commerz\Sales\Orders\Enums\OrderState;
use Commerz\Sales\Orders\Enums\PaymentState;
use Commerz\Sales\Payments\Data\InitiatePayment;
use Commerz\Sales\Payments\Data\RecordPaymentEvent;
use Commerz\Sales\Payments\Enums\PaymentEventType;
use Commerz\Sales\Tests\Fixtures\RecordingGateway;
use Commerz\Sales\Tests\Fixtures\StaticSellables;
use Commerz\Testkit\Concurrency;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Assert;

require_once __DIR__.'/../Helpers.php';

uses(SlsEnvironment::class);

// C4, C5 and C8 of knowledge/sales-design#tests across processes: a doubled webhook, parallel payments of one order,
// and a capture racing the expiry or the cancellation. Committed data, emptied after each test.

beforeEach(function (): void {
    if (! function_exists('pcntl_fork')) {
        Assert::markTestSkipped('pcntl is required.');
    }

    slsCommitted();
    StaticSellables::define(StaticSellables::item('a', '100.00'));
    slsConfig(['checkout' => ['require_delivery' => false]]);
    $this->order = slsPlace(slsCartWith(slsChannel(), ['test:a' => 2]));
});

afterEach(function (): void {
    StaticSellables::reset();
    RecordingGateway::reset();
    slsTruncate();
});

/**
 * @param  list<string>  $outcomes
 * @return array<string, int>
 */
function pcTally(array $outcomes): array
{
    $tally = array_count_values($outcomes);
    ksort($tally);

    return $tally;
}

it('captures the money of one event once however many deliveries race (C4)', function (): void {
    $payment = slsPay($this->order);
    $event = new RecordPaymentEvent('manual', 'bank-tx-42', PaymentEventType::Captured, paymentReference: $payment->reference);

    $outcomes = Concurrency::run(6, static fn (): string => slsOutcome(static function () use ($event): string {
        $result = slsPayments()->recordEvent($event);

        return ($result->duplicate ? 'duplicate ' : '').$result->outcome->value;
    }), timeoutSeconds: 60);

    expect(pcTally($outcomes))->toBe(['applied' => 1, 'duplicate applied' => 5])
        ->and(slsPayments()->find($payment->id)->captured)->toEqual($this->order->amountDue)
        ->and(slsOrders()->find($this->order->id))->paymentState->toBe(PaymentState::Paid)->captured->toEqual($this->order->amountDue)
        ->and(DB::table('cmz_payment_events')->count())->toBe(1)
        ->and(DB::table('cmz_outbox_messages')->where('type', 'sales.payment_state_changed')->count())->toBe(1);
})->group('concurrency');

it('lets one of the payments racing for the whole balance through, and calls the gateway once (C5)', function (): void {
    $order = $this->order->id;

    $outcomes = Concurrency::run(4, static fn (int $worker): string => slsOutcome(static fn (): string => slsPayments()->initiate(
        new InitiatePayment($order, 'recording', meta: ['sleep_ms' => 300], idempotencyKey: IdempotencyKey::of("pay-{$worker}")),
    )->status->value), timeoutSeconds: 60);

    expect(pcTally($outcomes))->toBe(['pending' => 1, 'sales.payment_exceeds_balance' => 3])
        ->and(RecordingGateway::calls('initiate'))->toHaveCount(1)
        ->and(DB::table('cmz_payments')->count())->toBe(1);
})->group('concurrency');

it('never expires an order whose payment is captured while the expiry runs (C8)', function (): void {
    $payment = slsPay($this->order);
    DB::table('cmz_orders')->update(['payment_due_at' => now('UTC')->subHour()->format('Y-m-d H:i:s.uP')]);
    $event = new RecordPaymentEvent('manual', 'bank-tx-1', PaymentEventType::Captured, paymentReference: $payment->reference);

    Concurrency::run(2, static fn (int $worker): string => $worker === 0
        ? (string) count(slsOrders()->expireDue(new ExpireOrders)->expired)
        : slsPayments()->recordEvent($event)->outcome->value, timeoutSeconds: 60);

    expect(slsOrders()->find($this->order->id))->state->toBe(OrderState::Placed)->paymentState->toBe(PaymentState::Paid);
})->group('concurrency');

it('asks once for a refund of money captured while the order is cancelled (C8)', function (): void {
    $payment = slsPay($this->order);
    $order = $this->order->id;
    $event = new RecordPaymentEvent('manual', 'bank-tx-1', PaymentEventType::Captured, paymentReference: $payment->reference);

    Concurrency::run(2, static fn (int $worker): string => $worker === 0
        ? slsOrders()->cancel(new CancelOrder($order, CancelReason::Customer))->state->value
        : slsPayments()->recordEvent($event)->outcome->value, timeoutSeconds: 60);

    expect(slsOrders()->find($order))->state->toBe(OrderState::Cancelled)->paymentState->toBe(PaymentState::Paid)
        ->and(DB::table('cmz_outbox_messages')->where('type', 'sales.payment_requires_refund')->count())->toBe(1)
        ->and(slsJsonObject(DB::table('cmz_outbox_messages')->where('type', 'sales.payment_requires_refund')->value('payload'))['amount_minor'])
        ->toBe($this->order->amountDue->amountMinor);
})->group('concurrency');
