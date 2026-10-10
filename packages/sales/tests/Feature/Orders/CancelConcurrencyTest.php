<?php

declare(strict_types=1);

use Commerz\Sales\Orders\Data\CancelOrder;
use Commerz\Sales\Orders\Data\ExpireOrders;
use Commerz\Sales\Orders\Data\OrderId;
use Commerz\Sales\Orders\Enums\CancelReason;
use Commerz\Sales\Tests\Fixtures\StaticSellables;
use Commerz\Testkit\Concurrency;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Helpers.php';

uses(SlsEnvironment::class);

// Two cancellations or two expiry passes of one order meet at its lock: one cancels, the other sees it cancelled or
// skips it. Committed data across processes, emptied after each test.

beforeEach(function (): void {
    if (! function_exists('pcntl_fork')) {
        $this->markTestSkipped('pcntl is required.');
    }

    $this->artisan('migrate')->assertSuccessful();
    StaticSellables::define(StaticSellables::item('a', '100.00'));
    slsConfig(['checkout' => ['require_delivery' => false]]);
    $this->web = slsChannel();
});

afterEach(function (): void {
    StaticSellables::reset();
    DB::statement('truncate cmz_payment_events, cmz_refunds, cmz_payments, cmz_return_lines, cmz_returns,'
        .' cmz_fulfillment_lines, cmz_fulfillments, cmz_order_events, cmz_order_lines, cmz_orders, cmz_cart_lines,'
        .' cmz_carts, cmz_channels, cmz_stores, cmz_outbox_messages, cmz_operation_receipts cascade');
});

it('cancels an order once for processes that cancel it together', function (): void {
    $order = slsPlace(slsCartWith($this->web, ['test:a' => 2]));

    $outcomes = Concurrency::run(4, fn (): string => slsOrders()->cancel(new CancelOrder($order->id, CancelReason::Customer))->state->value, timeoutSeconds: 60);

    expect($outcomes)->toBe(['cancelled', 'cancelled', 'cancelled', 'cancelled'])
        ->and(DB::table('cmz_order_events')->where('type', 'cancelled')->count())->toBe(1)
        ->and(DB::table('cmz_outbox_messages')->where('type', 'sales.order_cancelled')->count())->toBe(1)
        ->and(DB::table('cmz_order_lines')->value('cancelled_quantity'))->toBe(2);
})->group('concurrency');

it('expires each order once for passes that run together', function (): void {
    $orders = array_map(fn (): string => slsPlace(slsCartWith($this->web, ['test:a' => 1]))->id->value, range(1, 6));
    $later = new DateTimeImmutable('+4321 minutes');

    $outcomes = Concurrency::run(2, static function () use ($later): string {
        $report = slsOrders()->expireDue(new ExpireOrders($later));

        return implode(',', array_map(static fn (OrderId $id): string => $id->value, $report->expired)).'|'.$report->skipped;
    }, timeoutSeconds: 60);

    $expired = [];
    $skipped = 0;

    foreach ($outcomes as $outcome) {
        [$ids, $skips] = explode('|', $outcome);
        $expired = [...$expired, ...array_filter(explode(',', $ids))];
        $skipped += (int) $skips;
    }

    sort($expired);
    sort($orders);

    expect($expired)->toBe($orders)
        // A pass that reads its candidates after the other committed sees fewer of them.
        ->and($skipped)->toBeLessThanOrEqual(6)
        ->and(DB::table('cmz_order_events')->where('type', 'cancelled')->count())->toBe(6)
        ->and(DB::table('cmz_outbox_messages')->where('type', 'sales.order_cancelled')->count())->toBe(6)
        ->and(DB::table('cmz_orders')->where('cancel_reason', 'expired')->count())->toBe(6);
})->group('concurrency');
