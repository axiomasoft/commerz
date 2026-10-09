<?php

declare(strict_types=1);

use Commerz\Pricing\Facades\Pricing;
use Commerz\Pricing\PriceLists\Data\PriceListId;
use Commerz\Pricing\Prices\Data\SetPrices;
use Commerz\Pricing\Prices\Exceptions\PriceWindowOverlap;
use Commerz\Testkit\Concurrency;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../PriceLists/Helpers.php';

// Committed data across processes: no transactional isolation; the tables are emptied after each test. Lock timeouts
// stay at the default (3 s): a deadlock surfaces as SQLSTATE 40P01 and fails the worker.

beforeEach(function (): void {
    if (! function_exists('pcntl_fork')) {
        $this->markTestSkipped('pcntl is required.');
    }

    $this->artisan('migrate')->assertSuccessful();
});

afterEach(function (): void {
    DB::statement('truncate cmz_prices, cmz_price_list_channels, cmz_price_lists, cmz_channels, cmz_stores,'
        .' cmz_outbox_messages, cmz_operation_receipts cascade');
});

it('keeps one price when processes set overlapping windows of one key (PRC-01)', function (): void {
    $list = plsList();
    $listId = $list->id->value;

    $outcomes = Concurrency::run(6, function (int $worker) use ($listId): string {
        // Every window covers 2026-06-01, so any two of them overlap.
        try {
            plsSet(new PriceListId($listId), amount: '10.00', from: plsAt('2026-0'.(1 + $worker).'-01'), until: plsAt('2026-12-01'));

            return 'set';
        } catch (PriceWindowOverlap) {
            return 'overlap';
        }
    });

    $counts = array_count_values($outcomes);
    ksort($counts);

    expect($counts)->toBe(['overlap' => 5, 'set' => 1])
        ->and(DB::table('cmz_prices')->count())->toBe(1);
})->group('concurrency');

it('keeps one row and no error when processes set the identical price (PRC-01)', function (): void {
    $list = plsList();
    $listId = $list->id->value;

    $amounts = Concurrency::run(6, fn (int $worker): int => plsSet(
        new PriceListId($listId),
        amount: '10.0'.$worker,
        from: plsAt('2026-01-01'),
        until: plsAt('2026-02-01'),
    )->amount->amountMinor);

    expect(DB::table('cmz_prices')->count())->toBe(1)
        ->and(DB::table('cmz_prices')->value('amount_minor'))->toBeIn($amounts);
})->group('concurrency');

it('does not deadlock batches that share keys and list them in another order', function (): void {
    $list = plsList();
    $listId = $list->id->value;
    $sellables = ['a', 'b', 'c', 'd', 'e', 'f'];

    Concurrency::run(4, function (int $worker) use ($listId, $sellables): void {
        $id = new PriceListId($listId);
        $order = $worker % 2 === 0 ? $sellables : array_reverse($sellables);

        for ($round = 0; $round < 10; $round++) {
            Pricing::prices()->setMany(new SetPrices($id, array_map(
                static fn (string $sellable) => plsPrice($id, $sellable, (string) (1 + $worker + $round).'.00'),
                $order,
            )));
        }
    }, timeoutSeconds: 60);

    expect(DB::table('cmz_prices')->count())->toBe(count($sellables));
})->group('concurrency');
