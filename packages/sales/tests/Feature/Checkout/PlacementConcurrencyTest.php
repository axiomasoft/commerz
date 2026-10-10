<?php

declare(strict_types=1);

use Commerz\Identity\IdempotencyKey;
use Commerz\Sales\Carts\Data\CartView;
use Commerz\Sales\Checkout\Data\PlaceOrder;
use Commerz\Sales\Checkout\Exceptions\CartAlreadyPlaced;
use Commerz\Sales\Tests\Fixtures\StaticSellables;
use Commerz\Testkit\Concurrency;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Helpers.php';

uses(SlsEnvironment::class);

// C1–C3 of knowledge/sales-design#tests (ORD-01). Committed data across processes: no transactional isolation; the
// tables are emptied after each test. Sellables come from the static registry and stock is unlimited, since in-memory
// fakes are not shared with the forked workers.

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

function pccPlace(CartView $cart, ?string $key): string
{
    try {
        return slsCheckout()->place(new PlaceOrder($cart->id, slsCarts()->quote($cart->id)->total, $key === null ? null : IdempotencyKey::of($key)))->id->value;
    } catch (CartAlreadyPlaced) {
        return 'already_placed';
    }
}

it('places one order for processes that submit one cart with one key (C1)', function (): void {
    $cart = slsCartWith($this->web, ['test:a' => 2]);

    $outcomes = Concurrency::run(6, fn (): string => pccPlace($cart, 'checkout-1'), timeoutSeconds: 60);

    expect(array_unique($outcomes))->toHaveCount(1)
        ->and(DB::table('cmz_orders')->pluck('id')->all())->toBe([$outcomes[0]])
        ->and(DB::table('cmz_order_lines')->count())->toBe(1)
        ->and(DB::table('cmz_outbox_messages')->where('type', 'sales.order_placed')->count())->toBe(1)
        ->and(DB::table('cmz_carts')->where('id', $cart->id->value)->value('state'))->toBe('converted');
})->group('concurrency');

it('lets one of the processes that submit one cart without a key through (C2)', function (): void {
    $cart = slsCartWith($this->web, ['test:a' => 1]);

    $outcomes = Concurrency::run(6, fn (): string => pccPlace($cart, null), timeoutSeconds: 60);
    $placed = array_values(array_filter($outcomes, static fn (string $outcome): bool => $outcome !== 'already_placed'));

    expect($placed)->toHaveCount(1)
        ->and(array_count_values($outcomes)['already_placed'])->toBe(5)
        ->and(DB::table('cmz_orders')->pluck('id')->all())->toBe($placed);
})->group('concurrency');

it('numbers the orders of different carts placed together apart (C3)', function (): void {
    $carts = array_map(fn (): CartView => slsCartWith($this->web, ['test:a' => 1]), range(0, 3));

    $outcomes = Concurrency::run(4, fn (int $worker): string => pccPlace($carts[$worker], "checkout-{$worker}"), timeoutSeconds: 60);
    $numbers = DB::table('cmz_orders')->pluck('number')->all();

    expect(array_unique($outcomes))->toHaveCount(4)
        ->and($numbers)->toHaveCount(4)
        ->and(array_unique($numbers))->toHaveCount(4)
        ->and($numbers)->each->toMatch('/\AW-\d+\z/');
})->group('concurrency');
