<?php

declare(strict_types=1);

use Commerz\EndToEnd\Concurrency\Support\Checkout;
use Commerz\EndToEnd\Concurrency\Support\Outcome;
use Commerz\EndToEnd\Support\CommittedDatabase;
use Commerz\EndToEnd\Support\ShopWorld;
use Commerz\Inventory\Facades\Inventory;
use Commerz\Inventory\Movements\Data\ListMovements;
use Commerz\Inventory\Stock\Data\AdjustStock;
use Commerz\Inventory\Stock\Data\CountStock;
use Commerz\Inventory\Stock\Data\ReceiveStock;
use Commerz\Inventory\Stock\Enums\MovementKind;
use Commerz\Money\Money;
use Commerz\Ports\Promotions\Exceptions\UsageLimitReached;
use Commerz\Promotions\Facades\Promotions;
use Commerz\Sales\Checkout\Exceptions\QuoteChanged;
use Commerz\Sales\Common\Data\LineQuantity;
use Commerz\Sales\Facades\Sales;
use Commerz\Sales\Fulfillments\Data\CreateFulfillment;
use Commerz\Sales\Fulfillments\Exceptions\OrderNotFulfillable;
use Commerz\Sales\Orders\Data\CancelOrder;
use Commerz\Sales\Orders\Data\ExpireOrders;
use Commerz\Sales\Orders\Data\OrderView;
use Commerz\Sales\Orders\Enums\CancelReason;
use Commerz\Sales\Orders\Enums\FulfillmentState;
use Commerz\Sales\Orders\Enums\OrderState;
use Commerz\Sales\Orders\Enums\PaymentState;
use Commerz\Sales\Payments\Data\InitiatePayment;
use Commerz\Sales\Payments\Data\RecordPaymentEvent;
use Commerz\Sales\Payments\Enums\PaymentEventType;
use Commerz\Sales\Refunds\Data\IssueRefund;
use Commerz\Sales\Refunds\Enums\RefundStatus;
use Commerz\Testkit\Concurrency;
use Illuminate\Support\Facades\DB;
use Packman\Schema\Data\Compiler\TableNames;

// E4–E7 of assembly-design#concurrency: the life of placed orders against the stock, the money and the promotion
// counters of the real packages, two operations at a time over one shared resource. The world is committed in the
// parent before the workers fork; every scenario ends without a deadlock and with consistent stock and promotions.

beforeEach(function (): void {
    if (! function_exists('pcntl_fork')) {
        $this->markTestSkipped('pcntl is required.');
    }

    CommittedDatabase::prepare();
});

afterEach(fn () => CommittedDatabase::truncate());

it('reserves a size while the warehouse adjusts and counts it (E4)', function (): void {
    $world = ShopWorld::build();
    $carts = array_map(static fn (int $i): array => Checkout::cart($world, [[$world->small, 1]], "e4-{$i}"), range(0, 3));
    $levels = app(TableNames::class)->table('stock_levels');
    $level = (string) DB::table($levels)->where('stock_item_id', $world->smallItem->value)->where('location_id', $world->location->value)->value('id');
    $before = Outcome::deadlocks();

    // Worker 0 holds the level so that everyone else queues on it together; workers 1–4 place one S each, worker 5
    // receives three found units, worker 6 counts eight on the shelf. The count queues last (lock waits are granted in
    // order), so under the level lock it sees the adjusted 8 and changes nothing; a count that read the level without
    // the lock would add 8 − 5 on top of the adjustment.
    $outcomes = Concurrency::run(7, static fn (int $worker): string => Outcome::of(static function () use ($world, $carts, $worker, $levels, $level): string {
        if ($worker === 0) {
            return Outcome::gate($levels, $level);
        }

        usleep($worker === 6 ? 2 * Outcome::CONTENDER_DELAY_US : Outcome::CONTENDER_DELAY_US);
        match ($worker) {
            5 => Inventory::stock()->adjust(new AdjustStock($world->smallItem, $world->location, 3, 'found')),
            6 => Inventory::stock()->count(new CountStock($world->smallItem, $world->location, 8)),
            default => Checkout::place($carts[$worker - 1]),
        };

        return 'ok';
    }), timeoutSeconds: 60);
    $after = Inventory::stock()->level($world->smallItem, $world->location);
    $movements = Inventory::movements()->list(new ListMovements($world->smallItem, $world->location))->items;

    Outcome::assertSettled($outcomes, $before);

    // The journal is one chain: each movement starts where the previous one ended, a count ends exactly at what was
    // counted (a count computed from a stale level would not), and the level ends where the chain does. Reservations
    // never move on hand.
    $balance = 0;

    foreach ($movements as $movement) {
        expect($movement->balanceAfter)->toBe($balance + $movement->delta);
        $balance = $movement->balanceAfter;

        if ($movement->kind === MovementKind::Count) {
            expect($movement->balanceAfter)->toBe(8);
        }
    }

    expect(array_slice($outcomes, 1))->toBe(array_fill(0, 6, 'ok'))
        ->and(Outcome::waiting($outcomes))->toBe(6)
        ->and($after->onHand)->toBe($balance)
        ->and($after->onHand)->toBe(8)
        ->and($after->reserved)->toBe(4)
        ->and($after->available)->toBe($after->onHand - $after->reserved);
})->group('concurrency');

it('fulfils orders holding the same two goods in opposite line orders without a lock cycle (E3)', function (): void {
    $world = ShopWorld::build();
    Inventory::stock()->receive(new ReceiveStock($world->smallItem, $world->location, 20));
    Inventory::stock()->receive(new ReceiveStock($world->mugItem, $world->location, 20));
    $orders = array_map(static fn (int $i): OrderView => Checkout::place(Checkout::cart(
        $world,
        $i % 2 === 0 ? [[$world->small, 1], [$world->mug, 1]] : [[$world->mug, 1], [$world->small, 1]],
        "e3f-{$i}",
    )), range(0, 7));
    $before = Outcome::deadlocks();

    // A fulfillment consumes its lines one by one in one transaction; the stock must still take both levels in its key
    // order, whatever the order of the lines.
    $outcomes = Concurrency::run(8, static fn (int $worker): string => Outcome::of(static function () use ($orders, $worker): string {
        $order = $orders[$worker];
        Sales::fulfillments()->create(new CreateFulfillment($order->id, array_map(
            static fn ($line): LineQuantity => new LineQuantity($line->id, 1),
            $order->lines,
        )));

        return 'ok';
    }), timeoutSeconds: 60);

    Outcome::assertSettled($outcomes, $before);

    expect($outcomes)->toBe(array_fill(0, 8, 'ok'))
        ->and([Inventory::stock()->level($world->smallItem, $world->location)->onHand, Inventory::stock()->level($world->smallItem, $world->location)->reserved])->toBe([17, 0])
        ->and([Inventory::stock()->level($world->mugItem, $world->location)->onHand, Inventory::stock()->level($world->mugItem, $world->location)->reserved])->toBe([22, 0]);
})->group('concurrency');

it('ends a cancel racing the capture of an unpaid order in one consistent state (E5)', function (): void {
    $world = ShopWorld::build();
    $orders = array_map(static function (int $i) use ($world): array {
        $order = Checkout::place(Checkout::cart($world, [[$world->small, 1]], "e5-{$i}", coupon: true));

        return [$order, Sales::payments()->initiate(new InitiatePayment($order->id, 'manual'))->reference];
    }, range(0, 3));
    $before = Outcome::deadlocks();

    // Even workers cancel order k = worker / 2; odd workers record the capture of the same order.
    $outcomes = Concurrency::run(8, static fn (int $worker): string => Outcome::of(static function () use ($orders, $worker): string {
        [$order, $reference] = $orders[intdiv($worker, 2)];

        if ($worker % 2 === 0) {
            Sales::orders()->cancel(new CancelOrder($order->id, CancelReason::Customer));
        } else {
            Sales::payments()->recordEvent(new RecordPaymentEvent('manual', "e5-capture-{$worker}", PaymentEventType::Captured, paymentReference: $reference));
        }

        return 'ok';
    }), timeoutSeconds: 60);

    Outcome::assertSettled($outcomes, $before);

    // Whichever came first, the order ends cancelled with its money captured, and that money is surfaced as a refund
    // to make (D32), once; its stock and coupon use are given back.
    foreach ($orders as [$order]) {
        $final = Sales::orders()->find($order->id);
        $owed = array_filter(Sales::orders()->history($order->id), static fn ($event): bool => $event->type === 'payment_requires_refund');
        $reservation = Inventory::reservations()->find('order:'.$order->id->value);

        expect([$final->state, $final->paymentState])->toBe([OrderState::Cancelled, PaymentState::Paid])
            ->and($final->captured)->toEqual($order->total)
            ->and($owed)->toHaveCount(1)
            ->and(array_map(static fn ($line): array => [$line->released, $line->outstanding], $reservation->lines))->toBe([[1, 0]]);
    }

    expect($outcomes)->toBe(array_fill(0, 8, 'ok'))
        ->and(Promotions::promotions()->find($world->couponPromotion)->usage->orders)->toBe(0)
        ->and(Inventory::stock()->level($world->smallItem, $world->location)->reserved)->toBe(0);
})->group('concurrency');

it('lets either the expiry or the fulfillment of an unpaid order win, never both (E6)', function (): void {
    $world = ShopWorld::build();
    $orders = array_map(static fn (int $i): OrderView => Checkout::place(Checkout::cart($world, [[$world->small, 1]], "e6-{$i}")), range(0, 2));
    // Past every payment deadline (three days after placing by default).
    $after = new DateTimeImmutable('+4 days');
    $before = Outcome::deadlocks();

    // Workers 0 and 1 expire whatever is due; workers 2–4 fulfil order k = worker − 2.
    $outcomes = Concurrency::run(5, static fn (int $worker): string => Outcome::of(static function () use ($orders, $worker, $after): string {
        if ($worker < 2) {
            return 'expired:'.count(Sales::orders()->expireDue(new ExpireOrders($after))->expired);
        }

        $order = $orders[$worker - 2];
        Sales::fulfillments()->create(new CreateFulfillment($order->id, [new LineQuantity($order->lines[0]->id, 1)]));

        return 'fulfilled';
    }, [OrderNotFulfillable::class => 'not_fulfillable']), timeoutSeconds: 60);

    Outcome::assertSettled($outcomes, $before);

    $expired = 0;

    foreach ($orders as $k => $order) {
        $final = Sales::orders()->find($order->id);
        $line = Inventory::reservations()->find('order:'.$order->id->value)->lines[0];

        if ($outcomes[$k + 2] === 'fulfilled') {
            expect([$final->state, $final->fulfillmentState])->toBe([OrderState::Placed, FulfillmentState::Fulfilled])
                ->and([$line->consumed, $line->released])->toBe([1, 0]);
        } else {
            $expired++;
            expect($outcomes[$k + 2])->toBe('not_fulfillable')
                ->and([$final->state, $final->cancelReason])->toBe([OrderState::Cancelled, CancelReason::Expired])
                ->and([$line->consumed, $line->released])->toBe([0, 1]);
        }
    }

    $level = Inventory::stock()->level($world->smallItem, $world->location);

    // The two sweepers expired each order once between them.
    expect((int) substr($outcomes[0], 8) + (int) substr($outcomes[1], 8))->toBe($expired)
        ->and([$level->onHand, $level->reserved])->toBe([5 - (3 - $expired), 0]);
})->group('concurrency');

it('serializes the coupon use a full refund gives back with new orders spending it (E7)', function (): void {
    // The shop gives promotion usage back on a full refund (the default restores only on cancel and expiry).
    config(['commerz-promotions.restore_on' => ['cancelled', 'expired', 'refunded']]);
    $world = ShopWorld::build();
    Inventory::stock()->receive(new ReceiveStock($world->mugItem, $world->location, 10));

    // Four paid orders hold four of the coupon's five uses; four new carts are quoted while one use is still free.
    $paid = array_map(static function (int $i) use ($world): OrderView {
        $order = Checkout::place(Checkout::cart($world, [[$world->mug, 1]], "e7-paid-{$i}", coupon: true));
        Checkout::pay($order);

        return $order;
    }, range(0, 3));
    $carts = array_map(static fn (int $i): array => Checkout::cart($world, [[$world->mug, 1]], "e7-new-{$i}", coupon: true), range(0, 3));
    $before = Outcome::deadlocks();

    // Worker 0 holds the coupon's promotion row, so the restores and the consumes all queue on it at once, each with
    // the locks it took before (refunds: the order; placements: the mug's level). Workers 1–4 refund paid order k − 1
    // in full; workers 5–8 place new cart k − 5.
    $outcomes = Concurrency::run(9, static fn (int $worker): string => Outcome::of(static function () use ($world, $paid, $carts, $worker): string {
        if ($worker === 0) {
            return Outcome::gate(app(TableNames::class)->table('promotions'), $world->couponPromotion->value);
        }

        usleep(Outcome::CONTENDER_DELAY_US);

        if ($worker < 5) {
            $refund = Sales::refunds()->issue(new IssueRefund($paid[$worker - 1]->id, $paid[$worker - 1]->total));

            return $refund->status === RefundStatus::Succeeded ? 'refunded' : 'refund:'.$refund->status->value;
        }

        Checkout::place($carts[$worker - 5]);

        return 'ok';
    }, [UsageLimitReached::class => 'limit_reached', QuoteChanged::class => 'quote_changed']), timeoutSeconds: 60);

    Outcome::assertSettled($outcomes, $before);

    $placed = count(array_keys(array_slice($outcomes, 5), 'ok', true));

    // Every refund hands its use back; the counter ends at the uses of the orders that kept the coupon — the new ones.
    expect(Outcome::waiting($outcomes))->toBe(8)
        ->and(array_slice($outcomes, 1, 4))->toBe(array_fill(0, 4, 'refunded'))
        ->and(array_diff(array_slice($outcomes, 5), ['ok', 'limit_reached', 'quote_changed']))->toBe([])
        ->and($placed)->toBeGreaterThanOrEqual(1)
        ->and(Promotions::promotions()->find($world->couponPromotion)->usage->orders)->toBe($placed)
        ->and(array_map(static fn (OrderView $order): Money => Sales::orders()->find($order->id)->refunded, $paid))
        ->toEqual(array_map(static fn (OrderView $order): Money => $order->total, $paid));
})->group('concurrency');

it('serializes a placement spending a coupon with the cancellation giving one back (E8)', function (): void {
    $world = ShopWorld::build();
    Inventory::stock()->receive(new ReceiveStock($world->smallItem, $world->location, 10));
    // Four unpaid orders hold four of the coupon's five uses and four S; four new carts of S are quoted with the coupon.
    $placed = array_map(static fn (int $i): OrderView => Checkout::place(Checkout::cart($world, [[$world->small, 1]], "e8-old-{$i}", coupon: true)), range(0, 3));
    $carts = array_map(static fn (int $i): array => Checkout::cart($world, [[$world->small, 1]], "e8-new-{$i}", coupon: true), range(0, 3));
    $before = Outcome::deadlocks();

    // Worker 0 holds the promotion row. Cancelling locks the order, releases the S level, then restores the use;
    // placing reserves the S level, then consumes the use: one level and one promotion, taken in the same order by
    // both (consume before reserve, or restore before release, would make a cycle here).
    $outcomes = Concurrency::run(9, static fn (int $worker): string => Outcome::of(static function () use ($world, $placed, $carts, $worker): string {
        if ($worker === 0) {
            return Outcome::gate(app(TableNames::class)->table('promotions'), $world->couponPromotion->value);
        }

        usleep(Outcome::CONTENDER_DELAY_US);

        if ($worker < 5) {
            Sales::orders()->cancel(new CancelOrder($placed[$worker - 1]->id, CancelReason::Customer));

            return 'cancelled';
        }

        Checkout::place($carts[$worker - 5]);

        return 'ok';
    }, [UsageLimitReached::class => 'limit_reached', QuoteChanged::class => 'quote_changed']), timeoutSeconds: 60);

    Outcome::assertSettled($outcomes, $before);

    $new = count(array_keys(array_slice($outcomes, 5), 'ok', true));
    $level = Inventory::stock()->level($world->smallItem, $world->location);

    // All eight queue behind the gate: one holder of the S level on the promotion row, the rest on the level. A
    // placement that consumed before it reserved would then wait on the promotion while holding nothing, take it, and
    // deadlock with the cancellation that holds the level.
    expect(Outcome::waiting($outcomes))->toBe(8)
        ->and(array_slice($outcomes, 1, 4))->toBe(array_fill(0, 4, 'cancelled'))
        ->and(array_diff(array_slice($outcomes, 5), ['ok', 'limit_reached', 'quote_changed']))->toBe([])
        ->and($new)->toBeGreaterThanOrEqual(1)
        ->and(Promotions::promotions()->find($world->couponPromotion)->usage->orders)->toBe($new)
        ->and($level->reserved)->toBe($new);
})->group('concurrency');
