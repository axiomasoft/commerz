<?php

declare(strict_types=1);

use Commerz\Inventory\Facades\Inventory;
use Commerz\Inventory\Items\Data\StockItemId;
use Commerz\Inventory\Items\Enums\StockPolicy;
use Commerz\Inventory\Locations\Data\CreateLocation;
use Commerz\Inventory\Locations\Data\LocationId;
use Commerz\Inventory\Stock\Data\AdjustStock;
use Commerz\Inventory\Transfers\Data\DispatchTransfer;
use Commerz\Ports\Stock\Data\ReleaseReservation;
use Commerz\Stores\Data\ChannelId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Transfers/Helpers.php';

uses(RefreshDatabase::class);

/** @return array{ChannelId, StockItemId, LocationId} a level with 10 on hand, 4 reserved */
function rcnWorld(): array
{
    $channel = rsvChannel();
    $main = rsvSource($channel, 'main');
    $tee = rsvItem('tee', ['tee' => 1]);
    rsvReceive($tee, $main, 10);
    rsvReserve($channel, 'order:1', ['tee' => 4]);

    return [$channel, $tee, $main];
}

it('reports a clean state with exit 0', function (): void {
    rcnWorld();
    Inventory::stock()->adjust(new AdjustStock(rsvItem('cap', ['cap' => 1]), Inventory::locations()->create(new CreateLocation('aux', 'Aux'))->id, 3, 'found'));

    $this->pendingArtisan('commerz:inventory:reconcile')->expectsOutputToContain('Stock is consistent.')->assertSuccessful();
});

it('accepts active reservations without stock lines', function (string $kind): void {
    $channel = rsvChannel();

    if ($kind === 'untracked') {
        rsvItem('ebook', ['ebook' => 1], StockPolicy::Untracked);
    } else {
        rsvConfigure(['unlinked_sellables' => 'unlimited']);
    }

    rsvReserve($channel, 'order:ebook', ['ebook' => 2]);

    expect(Inventory::reservations()->find('order:ebook')->lines)->toBe([]);
    $this->pendingArtisan('commerz:inventory:reconcile')->assertSuccessful();
})->with(['untracked', 'unlinked-unlimited']);

it('accepts a header whose last outstanding lines were lazily expired', function (): void {
    $channel = rsvChannel();
    $main = rsvSource($channel, 'main');
    $tee = rsvItem('tee', ['tee' => 1]);
    rsvReceive($tee, $main, 2);
    rsvReserve($channel, 'cart:expired', ['tee' => 2], new DateTimeImmutable('-1 minute'));
    rsvReserve($channel, 'order:new', ['tee' => 2]);

    $line = Inventory::reservations()->find('cart:expired')->lines[0];
    expect([$line->expired, $line->outstanding])->toBe([2, 0]);
    $this->pendingArtisan('commerz:inventory:reconcile')->assertSuccessful();
});

it('still flags an active settled generation with released lines', function (): void {
    rcnWorld();
    Inventory::reservations()->release(new ReleaseReservation('order:1'));
    DB::table('cmz_stock_reservations')->where('demand', 'order:1')->update(['state' => 'active']);

    $this->pendingArtisan('commerz:inventory:reconcile')->expectsOutputToContain('reservation_state')->assertFailed();
});

it('finds a corrupted reserved and repairs only that with --repair-reserved', function (): void {
    [, $tee, $main] = rcnWorld();
    DB::table('cmz_stock_levels')->where('stock_item_id', $tee->value)->update(['reserved' => 9]);

    $this->pendingArtisan('commerz:inventory:reconcile')->expectsOutputToContain('reserved_sum')->assertFailed();
    $this->pendingArtisan('commerz:inventory:reconcile', ['--repair-reserved' => true])->expectsOutputToContain('Repaired reserved stock on 1 levels.')->assertSuccessful();

    $level = rsvLevel($tee, $main);

    expect([$level->onHand, $level->reserved])->toBe([10, 4]);
    $this->pendingArtisan('commerz:inventory:reconcile')->assertSuccessful();
});

it('flags a corrupted on_hand and never repairs it', function (): void {
    [, $tee, $main] = rcnWorld();
    DB::table('cmz_stock_levels')->where('stock_item_id', $tee->value)->update(['on_hand' => 12]);

    $this->pendingArtisan('commerz:inventory:reconcile', ['--repair-reserved' => true])
        ->expectsOutputToContain('ledger_sum')
        ->expectsOutputToContain('ledger_balance')
        ->assertFailed();

    expect(rsvLevel($tee, $main)->onHand)->toBe(12);
});

it('flags a closed reservation that still holds units', function (): void {
    rcnWorld();
    DB::table('cmz_stock_reservations')->where('demand', 'order:1')->update(['state' => 'closed']);

    $this->pendingArtisan('commerz:inventory:reconcile')->expectsOutputToContain('reservation_state')->assertFailed();
});

it('flags a completed transfer with goods still in transit', function (): void {
    [$from, $to, $item] = trfWorld();
    $sent = Inventory::transfers()->dispatch(new DispatchTransfer(trfCreate($from, $to, [$item->value => 5])->id));
    $this->pendingArtisan('commerz:inventory:reconcile')->assertSuccessful();

    DB::table('cmz_stock_transfers')->where('id', $sent->id->value)->update(['state' => 'completed']);

    $this->pendingArtisan('commerz:inventory:reconcile')->expectsOutputToContain('transfer_state')->assertFailed();
});
