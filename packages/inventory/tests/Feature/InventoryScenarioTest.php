<?php

declare(strict_types=1);

use Commerz\Identity\SellableRef;
use Commerz\Inventory\Facades\Inventory;
use Commerz\Inventory\Locations\Data\CreateLocation;
use Commerz\Inventory\Transfers\Data\DispatchTransfer;
use Commerz\Ports\Stock\Contracts\StockAvailability;
use Commerz\Ports\Stock\Contracts\StockReservations;
use Commerz\Ports\Stock\Contracts\StockReturns;
use Commerz\Ports\Stock\Data\ConsumeReservation;
use Commerz\Ports\Stock\Data\ReleaseReservation;
use Commerz\Ports\Stock\Data\RestockLine;
use Commerz\Ports\Stock\Data\RestockStock;
use Commerz\Stores\Data\ChannelId;
use Commerz\Testkit\Concurrency;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Transfers/Helpers.php';

// Committed data across processes: no transactional isolation; the stock tables are emptied after each test.

beforeEach(function (): void {
    if (! function_exists('pcntl_fork')) {
        $this->markTestSkipped('pcntl is required.');
    }

    $this->artisan('migrate')->assertSuccessful();
});

afterEach(function (): void {
    DB::statement('truncate cmz_stock_transfer_lines, cmz_stock_transfers, cmz_stock_reservation_lines, cmz_stock_reservations,'
        .' cmz_stock_movements, cmz_stock_levels, cmz_stock_item_links, cmz_stock_sources, cmz_stock_items, cmz_stock_locations,'
        .' cmz_channels, cmz_stores, cmz_outbox_messages, cmz_operation_receipts cascade');
});

/** One shopping round on shared kit items: reserve a kit, ship part of it, return it, release the rest. */
function scnRound(ChannelId $channel, string $demand, string $location): void
{
    $kit = SellableRef::of('variant', 'kit');
    $reservations = app(StockReservations::class);

    rsvReserve($channel, $demand, ['kit' => 2]);
    $reservations->consume(new ConsumeReservation($demand, $kit, 1, 'fulfillment:'.$demand, $location));
    app(StockReturns::class)->restock(new RestockStock('return:'.$demand, $channel, [new RestockLine($kit, 1)], $location));
    $reservations->release(new ReleaseReservation($demand));
}

it('runs a warehouse end to end and stays reconciled after concurrent rounds', function (): void {
    $channel = rsvChannel();
    $main = rsvSource($channel, 'main', 10);
    $shop = Inventory::locations()->create(new CreateLocation('shop', 'Shop'))->id;
    $body = rsvItem('body', ['kit' => 2]);
    $strap = rsvItem('strap', ['kit' => 1]);
    rsvReceive($body, $main, 100);
    rsvReceive($strap, $main, 50);

    $availability = app(StockAvailability::class);
    $held = rsvReserve($channel, 'order:1', ['kit' => 3]);

    expect(rsvHeld($held)['kit'])->toBe([['main', 3]])
        ->and(rsvLevel($body, $main)->reserved)->toBe(6)
        ->and($availability)->toBeInstanceOf(StockAvailability::class);

    $moved = Inventory::transfers()->dispatch(new DispatchTransfer(trfCreate($main, $shop, [$body->value => 10, $strap->value => 5])->id));
    trfReceive($moved, [$body->value => [10], $strap->value => [4, 1]]);

    app(StockReservations::class)->consume(new ConsumeReservation('order:1', SellableRef::of('variant', 'kit'), 3, 'fulfillment:1'));
    app(StockReturns::class)->restock(new RestockStock('return:1', $channel, [new RestockLine(SellableRef::of('variant', 'kit'), 1)], 'shop'));

    expect(rsvLevel($body, $shop)->onHand)->toBe(12)
        ->and(rsvLevel($strap, $shop)->onHand)->toBe(5);
    $this->artisan('commerz:inventory:reconcile')->assertSuccessful();

    Concurrency::run(4, function (int $worker) use ($channel): void {
        for ($i = 0; $i < 3; $i++) {
            scnRound($channel, "order:w{$worker}-{$i}", 'main');
        }
    });

    $this->artisan('commerz:inventory:reconcile')->expectsOutputToContain('Stock is consistent.')->assertSuccessful();
});
