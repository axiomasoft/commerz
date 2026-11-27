<?php

declare(strict_types=1);

use Commerz\EndToEnd\Concurrency\Support\Outcome;
use Commerz\EndToEnd\Support\CommittedDatabase;
use Commerz\EndToEnd\Support\ShopWorld;
use Commerz\Inventory\Items\Data\StockItemId;
use Commerz\Operations\Contracts\Transactions;
use Commerz\Schema\TableNames;
use Commerz\Testkit\Concurrency;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Assert;

// The positive control of the detector: two workers lock two stock levels in opposite orders on purpose. If the
// detector does not see the resulting 40P01 — in a worker and in pg_stat_database — every green concurrency test of
// this suite would prove nothing.

beforeEach(function (): void {
    if (! function_exists('pcntl_fork')) {
        Assert::markTestSkipped('pcntl is required.');
    }

    CommittedDatabase::prepare();
});

afterEach(fn () => CommittedDatabase::truncate());

it('sees a deadlock that two workers make on purpose', function (): void {
    $world = ShopWorld::build();
    $levels = app(TableNames::class)->table('stock_levels');
    $ids = array_map(
        static function (StockItemId $item) use ($levels, $world): string {
            $id = DB::table($levels)->where('stock_item_id', $item->value)->where('location_id', $world->location->value)->value('id');
            if (! is_string($id) && ! is_int($id)) {
                throw new RuntimeException('Stock level id is missing.');
            }

            return (string) $id;
        },
        [$world->smallItem, $world->mugItem],
    );
    $before = Outcome::deadlocks();

    $outcomes = Concurrency::run(2, static fn (int $worker): string => Outcome::of(static function () use ($worker, $ids, $levels): string {
        [$first, $second] = $worker === 0 ? $ids : array_reverse($ids);

        return app(Transactions::class)->run(static function () use ($first, $second, $levels): string {
            DB::select("select id from {$levels} where id = ? for update", [$first]);
            usleep(300_000);
            DB::select("select id from {$levels} where id = ? for update", [$second]);

            return 'ok';
        });
    }));
    sort($outcomes);

    expect($outcomes)->toBe(['error:40P01', 'ok'])
        ->and(Outcome::deadlocksSince($before))->toBeGreaterThanOrEqual(1);
})->group('concurrency');
