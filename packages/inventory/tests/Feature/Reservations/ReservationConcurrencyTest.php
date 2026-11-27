<?php

declare(strict_types=1);

use Commerz\Identity\SellableRef;
use Commerz\Inventory\Facades\Inventory;
use Commerz\Inventory\Reservations\Data\ExpireReservations;
use Commerz\Operations\Contracts\Transactions;
use Commerz\Ports\Stock\Data\ConsumeReservation;
use Commerz\Ports\Stock\Data\ReleaseReservation;
use Commerz\Ports\Stock\Data\StockAllocation;
use Commerz\Ports\Stock\Exceptions\InsufficientStock;
use Commerz\Ports\Stock\Exceptions\ReservationNotFound;
use Commerz\Testkit\Concurrency;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Assert;

require_once __DIR__.'/Helpers.php';

// Committed data across processes: no transactional isolation; the tables are emptied after each test. Lock timeouts
// stay at the default (3 s): a deadlock surfaces as SQLSTATE 40P01 and fails the worker.

beforeEach(function (): void {
    if (! function_exists('pcntl_fork')) {
        Assert::markTestSkipped('pcntl is required.');
    }

    $this->pendingArtisan('migrate')->assertSuccessful();
});

afterEach(function (): void {
    DB::statement('truncate cmz_stock_reservation_lines, cmz_stock_reservations, cmz_stock_movements, cmz_stock_levels,'
        .' cmz_stock_item_links, cmz_stock_sources, cmz_stock_items, cmz_stock_locations, cmz_channels, cmz_stores,'
        .' cmz_outbox_messages, cmz_operation_receipts cascade');
});

it('sells the last units exactly once (INV-02)', function (): void {
    $channel = rsvChannel();
    $main = rsvSource($channel, 'main');
    $tee = rsvItem('tee', ['tee' => 1]);
    rsvReceive($tee, $main, 5);

    $outcomes = Concurrency::run(8, function (int $worker) use ($channel): string {
        try {
            rsvReserve($channel, "order:{$worker}", ['tee' => 1]);

            return 'reserved';
        } catch (InsufficientStock) {
            return 'insufficient';
        }
    });

    $counts = array_count_values($outcomes);
    ksort($counts);

    expect($counts)->toBe(['insufficient' => 3, 'reserved' => 5])
        ->and(rsvLevel($tee, $main)->reserved)->toBe(5)
        ->and((int) DB::table('cmz_stock_reservation_lines')->sum('units'))->toBe(5);
})->group('concurrency');

it('never over-reserves a component shared by kits (INV-02)', function (): void {
    $channel = rsvChannel();
    $main = rsvSource($channel, 'main');
    $wheel = rsvItem('wheel', ['bike' => 2, 'cart' => 2]);
    $frame = rsvItem('frame', ['bike' => 1]);
    rsvReceive($wheel, $main, 9);
    rsvReceive($frame, $main, 10);

    $outcomes = Concurrency::run(8, function (int $worker) use ($channel): string {
        $kit = $worker % 2 === 0 ? 'bike' : 'cart';

        try {
            rsvReserve($channel, "order:{$worker}", [$kit => 1]);

            return "{$kit}:reserved";
        } catch (InsufficientStock) {
            return "{$kit}:insufficient";
        }
    });

    $bikes = count(array_keys($outcomes, 'bike:reserved', true));
    $reserved = count(array_filter($outcomes, static fn (string $outcome): bool => str_ends_with($outcome, ':reserved')));

    expect($reserved)->toBe(4)
        ->and(rsvLevel($wheel, $main)->reserved)->toBe(8)
        ->and(rsvLevel($frame, $main)->reserved)->toBe($bikes);
})->group('concurrency');

it('does not deadlock carts that list the same items in reverse order', function (): void {
    $channel = rsvChannel();
    $main = rsvSource($channel, 'main', 10);
    $backup = rsvSource($channel, 'backup', 20);
    $tee = rsvItem('tee', ['tee' => 1]);
    $cap = rsvItem('cap', ['cap' => 1]);

    foreach ([$tee, $cap] as $item) {
        rsvReceive($item, $main, 50);
        rsvReceive($item, $backup, 50);
    }

    Concurrency::run(4, function (int $worker) use ($channel): void {
        $lines = $worker % 2 === 0 ? ['tee' => 30, 'cap' => 30] : ['cap' => 30, 'tee' => 30];

        for ($i = 0; $i < 20; $i++) {
            try {
                rsvReserve($channel, "cart:{$worker}:{$i}", $lines);
                Inventory::reservations()->release(new ReleaseReservation("cart:{$worker}:{$i}"));
            } catch (InsufficientStock) {
                // Another cart holds the stock right now; only a deadlock or lock timeout fails the worker.
            }
        }
    }, timeoutSeconds: 60);

    expect(rsvLevel($tee, $main)->reserved + rsvLevel($tee, $backup)->reserved)->toBe(0)
        ->and(rsvLevel($cap, $main)->reserved + rsvLevel($cap, $backup)->reserved)->toBe(0);
})->group('concurrency');

it('does not deadlock documents that consume or release the same items line by line in opposite orders', function (): void {
    $channel = rsvChannel();
    $main = rsvSource($channel, 'main');
    $tee = rsvItem('tee', ['tee' => 1]);
    $cap = rsvItem('cap', ['cap' => 1]);
    rsvReceive($tee, $main, 50);
    rsvReceive($cap, $main, 50);

    foreach (range(0, 7) as $worker) {
        rsvReserve($channel, "order:{$worker}", ['tee' => 1, 'cap' => 1]);
    }

    // A fulfillment consumes its lines and a partial cancellation releases them one call at a time inside one
    // transaction (sales); the first call must take every level the demand holds in key order, or two documents in
    // opposite line order deadlock (40P01). Workers 0–3 consume, 4–7 release; even workers go tee → cap, odd cap → tee.
    Concurrency::run(8, function (int $worker): void {
        $lines = $worker % 2 === 0 ? ['tee', 'cap'] : ['cap', 'tee'];

        app(Transactions::class)->run(function () use ($worker, $lines): void {
            foreach ($lines as $sku) {
                $sellable = SellableRef::of('variant', $sku);
                $worker < 4
                    ? Inventory::reservations()->consume(new ConsumeReservation("order:{$worker}", $sellable, 1, "fulfillment:{$worker}"))
                    : Inventory::reservations()->release(new ReleaseReservation("order:{$worker}", $sellable, 1));
                usleep(100_000);
            }
        });
    }, timeoutSeconds: 60);

    expect([rsvLevel($tee, $main)->onHand, rsvLevel($tee, $main)->reserved])->toBe([46, 0])
        ->and([rsvLevel($cap, $main)->onHand, rsvLevel($cap, $main)->reserved])->toBe([46, 0]);
})->group('concurrency');

it('lets either the consume or the expiry of one demand win, never both (INV-04)', function (): void {
    $channel = rsvChannel();
    $main = rsvSource($channel, 'main');
    $tee = rsvItem('tee', ['tee' => 1]);
    rsvReceive($tee, $main, 100);
    $sku = SellableRef::of('variant', 'tee');

    for ($i = 0; $i < 20; $i++) {
        rsvReserve($channel, "cart:{$i}", ['tee' => 2], new DateTimeImmutable('-1 second'));

        Concurrency::run(2, function (int $worker) use ($i, $sku): string {
            if ($worker === 1) {
                return 'expired:'.Inventory::reservations()->expire(new ExpireReservations);
            }

            try {
                Inventory::reservations()->consume(new ConsumeReservation("cart:{$i}", $sku, 2, "fulfillment:{$i}"));

                return 'consumed';
            } catch (ReservationNotFound) {
                return 'missed';
            }
        });

        $line = DB::table('cmz_stock_reservation_lines as ln')
            ->join('cmz_stock_reservations as r', 'r.id', '=', 'ln.reservation_id')
            ->where('r.demand', "cart:{$i}")
            ->first(['ln.units', 'ln.consumed_units', 'ln.expired_units', 'ln.released_units']);

        if (! $line instanceof stdClass) {
            throw new RuntimeException('expected a reservation line');
        }

        $counters = get_object_vars($line);
        $consumed = $counters['consumed_units'] ?? null;
        $expired = $counters['expired_units'] ?? null;
        $released = $counters['released_units'] ?? null;

        if (! is_int($consumed) || ! is_int($expired) || ! is_int($released)) {
            throw new RuntimeException('reservation counters must be integers');
        }

        expect([$consumed + $expired, $released])->toBe([2, 0])
            ->and(in_array($consumed, [0, 2], true))->toBeTrue();
    }

    $consumed = (int) DB::table('cmz_stock_reservation_lines')->sum('consumed_units');
    $level = rsvLevel($tee, $main);

    expect($level->reserved)->toBe(0)
        ->and($level->onHand)->toBe(100 - $consumed)
        ->and(DB::table('cmz_stock_reservations')->where('state', 'active')->count())->toBe(0)
        ->and((int) DB::table('cmz_stock_movements')->where('kind', 'sale')->sum('delta'))->toBe(-$consumed);
})->group('concurrency');

it('holds one set of lines when processes reserve the same demand (INV-05)', function (): void {
    $channel = rsvChannel();
    $main = rsvSource($channel, 'main', 10);
    $backup = rsvSource($channel, 'backup', 20);
    $tee = rsvItem('tee', ['tee' => 1]);
    rsvReceive($tee, $main, 2);
    rsvReceive($tee, $backup, 5);

    $results = Concurrency::run(4, fn (): array => array_map(
        static fn (StockAllocation $allocation): array => [$allocation->locationCode, $allocation->quantity],
        rsvReserve($channel, 'order:1', ['tee' => 3])->lines[0]->allocations,
    ));

    expect(array_unique(array_map(serialize(...), $results)))->toHaveCount(1)
        ->and($results[0])->toBe([['main', 2], ['backup', 1]])
        ->and(DB::table('cmz_stock_reservations')->count())->toBe(1)
        ->and(DB::table('cmz_stock_reservation_lines')->count())->toBe(2)
        ->and(rsvLevel($tee, $main)->reserved + rsvLevel($tee, $backup)->reserved)->toBe(3);
})->group('concurrency');
