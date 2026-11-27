<?php

declare(strict_types=1);

use Commerz\Errors\InvalidCommand;
use Commerz\Inventory\Facades\Inventory;
use Commerz\Inventory\Sources\Data\AssignSource;
use Commerz\Inventory\Transfers\Data\CancelTransfer;
use Commerz\Inventory\Transfers\Data\DispatchTransfer;
use Commerz\Inventory\Transfers\Enums\TransferState;
use Commerz\Inventory\Transfers\Exceptions\InvalidTransferState;
use Commerz\Ports\Stock\Exceptions\InsufficientStock;
use Commerz\Testkit\Concurrency;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Assert;

require_once __DIR__.'/Helpers.php';

// Committed data across processes (see ReservationConcurrencyTest); a deadlock fails the worker as SQLSTATE 40P01.

beforeEach(function (): void {
    if (! function_exists('pcntl_fork')) {
        Assert::markTestSkipped('pcntl is required.');
    }

    $this->pendingArtisan('migrate')->assertSuccessful();
});

afterEach(function (): void {
    DB::statement('truncate cmz_stock_transfer_lines, cmz_stock_transfers, cmz_stock_reservation_lines, cmz_stock_reservations,'
        .' cmz_stock_movements, cmz_stock_levels, cmz_stock_item_links, cmz_stock_sources, cmz_stock_items, cmz_stock_locations,'
        .' cmz_channels, cmz_stores, cmz_outbox_messages, cmz_operation_receipts cascade');
});

it('ships a transfer exactly once under concurrent dispatches (INV-06)', function (): void {
    [$from, $to, $item] = trfWorld();
    $draft = trfCreate($from, $to, [$item->value => 4]);

    $outcomes = Concurrency::run(4, function () use ($draft): string {
        try {
            Inventory::transfers()->dispatch(new DispatchTransfer($draft->id));

            return 'dispatched';
        } catch (InvalidTransferState) {
            return 'refused';
        }
    });

    $counts = array_count_values($outcomes);
    ksort($counts);
    $level = rsvLevel($item, $from);

    expect($counts)->toBe(['dispatched' => 1, 'refused' => 3])
        ->and([$level->onHand, $level->reserved])->toBe([6, 0])
        ->and(DB::table('cmz_stock_movements')->where('kind', 'transfer_out')->count())->toBe(1);
})->group('concurrency');

it('never receives more than was shipped (INV-06)', function (): void {
    [$from, $to, $item] = trfWorld();
    $sent = Inventory::transfers()->dispatch(new DispatchTransfer(trfCreate($from, $to, [$item->value => 5])->id));

    $outcomes = Concurrency::run(8, function () use ($sent, $item): string {
        try {
            trfReceive($sent, [$item->value => [1]]);

            return 'received';
        } catch (InvalidCommand|InvalidTransferState) {
            return 'refused';
        }
    });

    $counts = array_count_values($outcomes);
    ksort($counts);
    $line = Inventory::transfers()->find($sent->id)->lines[0];

    expect($counts)->toBe(['received' => 5, 'refused' => 3])
        ->and([$line->received, $line->lost, $line->inTransit])->toBe([5, 0, 0])
        ->and(Inventory::transfers()->find($sent->id)->state)->toBe(TransferState::Completed)
        ->and(rsvLevel($item, $to)->onHand)->toBe(5)
        ->and(rsvLevel($item, $from)->onHand)->toBe(5);
})->group('concurrency');

it('lets exactly one of a cancel and a dispatch win', function (): void {
    [$from, $to, $item] = trfWorld();
    $draft = trfCreate($from, $to, [$item->value => 4]);

    $outcomes = Concurrency::run(4, function (int $worker) use ($draft): string {
        try {
            $worker % 2 === 0
                ? Inventory::transfers()->dispatch(new DispatchTransfer($draft->id))
                : Inventory::transfers()->cancel(new CancelTransfer($draft->id));

            return $worker % 2 === 0 ? 'dispatched' : 'cancelled';
        } catch (InvalidTransferState) {
            return 'refused';
        }
    });

    $state = Inventory::transfers()->find($draft->id)->state;
    $level = rsvLevel($item, $from);

    expect(in_array($state, [TransferState::InTransit, TransferState::Cancelled], true))->toBeTrue()
        ->and($level->reserved)->toBe(0)
        ->and($level->onHand)->toBe($state === TransferState::InTransit ? 6 : 10)
        ->and(count(array_filter($outcomes, static fn (string $outcome): bool => $outcome !== 'refused')))->toBeGreaterThanOrEqual(1);
})->group('concurrency');

it('keeps the last units for exactly one of competing transfers and sales (INV-02)', function (): void {
    [$from, $to, $item] = trfWorld(5);
    $channel = rsvChannel();
    Inventory::sources()->assign(new AssignSource($channel, $from, 10));

    $outcomes = Concurrency::run(8, function (int $worker) use ($channel, $from, $to, $item): string {
        try {
            $worker % 2 === 0
                ? trfCreate($from, $to, [$item->value => 1])
                : rsvReserve($channel, "order:{$worker}", ['tee' => 1]);

            return 'held';
        } catch (InsufficientStock) {
            return 'insufficient';
        }
    });

    $counts = array_count_values($outcomes);
    ksort($counts);

    expect($counts)->toBe(['held' => 5, 'insufficient' => 3])
        ->and(rsvLevel($item, $from)->reserved)->toBe(5);
})->group('concurrency');
