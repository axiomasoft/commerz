<?php

declare(strict_types=1);

use Commerz\Money\Money;
use Commerz\Sales\Orders\Enums\PaymentState;
use Commerz\Sales\Refunds\Data\ListRefunds;
use Commerz\Sales\Refunds\Data\RefundView;
use Commerz\Sales\Refunds\Enums\RefundStatus;
use Commerz\Sales\Tests\Fixtures\RecordingGateway;
use Commerz\Sales\Tests\Fixtures\StaticSellables;
use Commerz\Testkit\Concurrency;

require_once __DIR__.'/Helpers.php';

uses(SlsEnvironment::class);

// C7 of knowledge/sales-design#tests across processes: parallel refunds of the whole captured amount of one order.
// What a payment holds is checked under the locks of the order and the payment together with the insert of the
// attempt, so exactly one refund goes to the gateway. Committed data, emptied after each test.

beforeEach(function (): void {
    if (! function_exists('pcntl_fork')) {
        $this->markTestSkipped('pcntl is required.');
    }

    slsCommitted();
    StaticSellables::define(StaticSellables::item('a', '100.00'));
    slsConfig(['checkout' => ['require_delivery' => false]]);
    $this->order = slsPlace(slsCartWith(slsChannel(), ['test:a' => 2]));
    $this->payment = rfRecorded($this->order);
});

afterEach(function (): void {
    StaticSellables::reset();
    RecordingGateway::reset();
    slsTruncate();
});

it('refunds the captured money once however many full refunds race (C7)', function (): void {
    RecordingGateway::scenario(['sleep_ms' => 300]);
    $order = $this->order;

    $outcomes = Concurrency::run(4, static fn (): string => slsOutcome(
        static fn (): string => rfIssue($order, '200.00')->status->value,
    ), timeoutSeconds: 60);

    $tally = array_count_values($outcomes);
    ksort($tally);
    $refunds = slsRefunds()->list(new ListRefunds($order->id))->items;

    expect($tally)->toBe(['sales.refund_exceeds_balance' => 3, 'succeeded' => 1])
        ->and(array_map(static fn (RefundView $refund): RefundStatus => $refund->status, $refunds))->toBe([RefundStatus::Succeeded])
        ->and(RecordingGateway::calls('refund'))->toBe(['refund '.$refunds[0]->reference])
        ->and(slsPayments()->find($this->payment->id)->refunded)->toEqual(Money::of('200.00', 'RUB'))
        ->and(slsOrders()->find($order->id)->paymentState)->toBe(PaymentState::Refunded);
})->group('concurrency');
