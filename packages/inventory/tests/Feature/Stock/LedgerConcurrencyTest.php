<?php

declare(strict_types=1);

use Commerz\Inventory\Facades\Inventory;
use Commerz\Inventory\Items\Data\ArchiveStockItem;
use Commerz\Inventory\Items\Data\CreateStockItem;
use Commerz\Inventory\Items\Enums\StockItemStatus;
use Commerz\Inventory\Items\Exceptions\StockItemArchived;
use Commerz\Inventory\Items\Exceptions\StockItemNotEmpty;
use Commerz\Inventory\Locations\Data\ArchiveLocation;
use Commerz\Inventory\Locations\Data\CreateLocation;
use Commerz\Inventory\Locations\Enums\LocationStatus;
use Commerz\Inventory\Locations\Exceptions\LocationArchived;
use Commerz\Inventory\Locations\Exceptions\LocationNotEmpty;
use Commerz\Inventory\Stock\Data\AdjustStock;
use Commerz\Inventory\Stock\Data\ReceiveStock;
use Commerz\Testkit\Concurrency;
use Illuminate\Support\Facades\DB;

// Committed data across processes: no transactional isolation; the stock tables are emptied after each test.

beforeEach(function (): void {
    if (! function_exists('pcntl_fork')) {
        $this->markTestSkipped('pcntl is required.');
    }

    $this->artisan('migrate')->assertSuccessful();
});

afterEach(function (): void {
    DB::statement('truncate cmz_stock_movements, cmz_stock_levels, cmz_stock_item_links, cmz_stock_sources,'
        .' cmz_stock_items, cmz_stock_locations, cmz_outbox_messages, cmz_operation_receipts cascade');
});

it('journals every concurrent change of one level exactly once with sequential balances (INV-03)', function (): void {
    $item = Inventory::items()->create(new CreateStockItem('tee'))->id;
    $location = Inventory::locations()->create(new CreateLocation('main', 'Main'))->id;
    Inventory::stock()->receive(new ReceiveStock($item, $location, 100));

    Concurrency::run(8, function (int $worker) use ($item, $location): void {
        for ($i = 0; $i < 10; $i++) {
            ($worker + $i) % 2 === 0
                ? Inventory::stock()->receive(new ReceiveStock($item, $location, 1))
                : Inventory::stock()->adjust(new AdjustStock($item, $location, -1, 'shrinkage'));
        }
    });

    $moves = DB::table('cmz_stock_movements')->orderBy('id')->get(['delta', 'balance_after'])->all();
    $level = DB::table('cmz_stock_levels')->first();
    $balance = 0;
    $sequential = true;

    foreach ($moves as $move) {
        $balance += $move->delta;
        $sequential = $sequential && $move->balance_after === $balance;
    }

    expect($moves)->toHaveCount(81)
        ->and($sequential)->toBeTrue()
        ->and($level->on_hand)->toBe($balance)
        ->and($level->on_hand)->toBe(100)
        ->and($level->version)->toBe(81)
        ->and(DB::table('cmz_outbox_messages')->count())->toBe(81);
})->group('concurrency');

it('never leaves stock on an archived location or item when archive races a receipt', function (string $target): void {
    for ($round = 0; $round < 10; $round++) {
        $item = Inventory::items()->create(new CreateStockItem("tee-{$round}"))->id;
        $location = Inventory::locations()->create(new CreateLocation("loc-{$round}", 'Loc'))->id;

        $results = Concurrency::run(2, function (int $worker) use ($item, $location, $target): string {
            try {
                if ($worker === 0) {
                    $target === 'location'
                        ? Inventory::locations()->archive(new ArchiveLocation($location))
                        : Inventory::items()->archive(new ArchiveStockItem($item));

                    return 'archived';
                }

                Inventory::stock()->receive(new ReceiveStock($item, $location, 1));

                return 'received';
            } catch (LocationNotEmpty|StockItemNotEmpty|LocationArchived|StockItemArchived $e) {
                return $e::class;
            }
        });

        $archived = $target === 'location'
            ? Inventory::locations()->find($location)->status === LocationStatus::Archived
            : Inventory::items()->find($item)->status === StockItemStatus::Archived;

        expect($archived && Inventory::stock()->level($item, $location)->onHand > 0)->toBeFalse()
            ->and(in_array($results, [
                ['archived', $target === 'location' ? LocationArchived::class : StockItemArchived::class],
                [$target === 'location' ? LocationNotEmpty::class : StockItemNotEmpty::class, 'received'],
            ], true))->toBeTrue();
    }
})->with(['location', 'item'])->group('concurrency');
