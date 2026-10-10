<?php

declare(strict_types=1);

use Commerz\EndToEnd\Concurrency\Support\Checkout;
use Commerz\EndToEnd\Concurrency\Support\Outcome;
use Commerz\EndToEnd\Support\CommittedDatabase;
use Commerz\EndToEnd\Support\ShopWorld;
use Commerz\Inventory\Facades\Inventory;
use Commerz\Inventory\Stock\Data\ReceiveStock;
use Commerz\Ports\Promotions\Exceptions\UsageLimitReached;
use Commerz\Ports\Stock\Exceptions\InsufficientStock;
use Commerz\Promotions\Facades\Promotions;
use Commerz\Sales\Checkout\Exceptions\QuoteChanged;
use Commerz\Sales\Facades\Sales;
use Commerz\Sales\Orders\Data\ListOrders;
use Commerz\Testkit\Concurrency;

// E1–E3 of assembly-design#concurrency: placements on the real inventory and promotions at once. `Checkout::place`
// reserves the stock (levels in key order) and then consumes the promotion usage (coupon → promotion → customer) in
// one transaction; the composition must neither oversell, nor overspend a limit, nor make a lock cycle. The world is
// committed in the parent before the workers fork.

beforeEach(function (): void {
    if (! function_exists('pcntl_fork')) {
        $this->markTestSkipped('pcntl is required.');
    }

    CommittedDatabase::prepare();
});

afterEach(fn () => CommittedDatabase::truncate());

it('sells the last units of a size to exactly as many carts (E1)', function (): void {
    $world = ShopWorld::build();
    $carts = array_map(static fn (int $i): array => Checkout::cart($world, [[$world->small, 1]], "e1-{$i}"), range(0, 11));
    $before = Outcome::deadlocks();

    $outcomes = Concurrency::run(12, static fn (int $worker): string => Outcome::of(
        static function () use ($carts, $worker): string {
            Checkout::place($carts[$worker]);

            return 'ok';
        },
        [InsufficientStock::class => 'insufficient_stock'],
    ), timeoutSeconds: 60);
    $level = Inventory::stock()->level($world->smallItem, $world->location);

    Outcome::assertSettled($outcomes, $before);

    expect(array_count_values($outcomes))->toEqual(['ok' => 5, 'insufficient_stock' => 7])
        ->and([$level->onHand, $level->reserved, $level->available])->toBe([5, 5, 0])
        ->and(Sales::orders()->list(new ListOrders)->items)->toHaveCount(5)
        // A refused placement rolled its promotion usage back with it.
        ->and(Promotions::promotions()->find($world->automaticPromotion)->usage->orders)->toBe(5);
})->group('concurrency');

it('spends a coupon limit of five on exactly five orders (E2)', function (): void {
    $world = ShopWorld::build();
    Inventory::stock()->receive(new ReceiveStock($world->mugItem, $world->location, 10));
    // Every cart is quoted while the coupon is still free, so each expects the coupon's discount.
    $carts = array_map(static fn (int $i): array => Checkout::cart($world, [[$world->mug, 1]], "e2-{$i}", coupon: true), range(0, 11));
    $before = Outcome::deadlocks();

    $outcomes = Concurrency::run(12, static fn (int $worker): string => Outcome::of(
        static function () use ($carts, $worker): string {
            Checkout::place($carts[$worker]);

            return 'ok';
        },
        // Consuming the sixth use fails in the port; a placement that re-quotes after the limit is reached no longer
        // gets the coupon and fails on the changed total. Both refuse the order; neither spends a use.
        [UsageLimitReached::class => 'limit_reached', QuoteChanged::class => 'quote_changed'],
    ), timeoutSeconds: 60);
    $mug = Inventory::stock()->level($world->mugItem, $world->location);
    $orders = Sales::orders()->list(new ListOrders)->items;

    Outcome::assertSettled($outcomes, $before);

    expect(array_count_values($outcomes)['ok'] ?? 0)->toBe(5)
        ->and(array_diff($outcomes, ['ok', 'limit_reached', 'quote_changed']))->toBe([])
        ->and($orders)->toHaveCount(5)
        ->and(array_map(static fn ($order): array => $order->codes, $orders))->each->toBe([ShopWorld::COUPON])
        ->and(Promotions::promotions()->find($world->couponPromotion)->usage->orders)->toBe(5)
        ->and($mug->reserved)->toBe(5);
})->group('concurrency');

it('places carts holding the same two goods in opposite orders without a lock cycle (E3)', function (): void {
    $world = ShopWorld::build();
    Inventory::stock()->receive(new ReceiveStock($world->smallItem, $world->location, 20));
    Inventory::stock()->receive(new ReceiveStock($world->mugItem, $world->location, 20));
    $carts = array_map(static fn (int $i): array => Checkout::cart(
        $world,
        $i % 2 === 0 ? [[$world->small, 1], [$world->mug, 1]] : [[$world->mug, 1], [$world->small, 1]],
        "e3-{$i}",
    ), range(0, 15));
    $before = Outcome::deadlocks();

    $outcomes = Concurrency::run(16, static fn (int $worker): string => Outcome::of(static function () use ($carts, $worker): string {
        Checkout::place($carts[$worker]);

        return 'ok';
    }), timeoutSeconds: 60);

    Outcome::assertSettled($outcomes, $before);

    expect($outcomes)->toBe(array_fill(0, 16, 'ok'))
        ->and(Inventory::stock()->level($world->smallItem, $world->location)->reserved)->toBe(16)
        ->and(Inventory::stock()->level($world->mugItem, $world->location)->reserved)->toBe(16);
})->group('concurrency');
