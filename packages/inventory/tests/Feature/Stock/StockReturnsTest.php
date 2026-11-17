<?php

declare(strict_types=1);

use Commerz\Errors\IdempotencyConflict;
use Commerz\Errors\InvalidCommand;
use Commerz\Identity\SellableRef;
use Commerz\Inventory\Configuration\InventoryOptions;
use Commerz\Inventory\Facades\Inventory;
use Commerz\Inventory\Items\Data\CreateStockItem;
use Commerz\Inventory\Items\Data\LinkSellable;
use Commerz\Inventory\Items\Data\StockItemId;
use Commerz\Inventory\Items\Enums\StockPolicy;
use Commerz\Inventory\Locations\Data\ArchiveLocation;
use Commerz\Inventory\Locations\Data\CreateLocation;
use Commerz\Inventory\Locations\Exceptions\LocationArchived;
use Commerz\Inventory\Locations\Exceptions\LocationNotFound;
use Commerz\Inventory\Movements\Data\ListMovements;
use Commerz\Inventory\Movements\Data\MovementView;
use Commerz\Inventory\Sources\Data\AssignSource;
use Commerz\Inventory\Stock\Enums\MovementKind;
use Commerz\Inventory\Stock\Exceptions\StockLinkMissing;
use Commerz\Inventory\Stock\Internal\DatabaseStockReturns;
use Commerz\Money\Currency;
use Commerz\Ports\Stock\Contracts\StockReturns;
use Commerz\Ports\Stock\Data\RestockLine;
use Commerz\Ports\Stock\Data\RestockStock;
use Commerz\Stores\Contracts\Channels;
use Commerz\Stores\Contracts\Stores;
use Commerz\Stores\Data\ChannelId;
use Commerz\Stores\Data\CreateChannel;
use Commerz\Stores\Data\CreateStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function rtnChannel(): ChannelId
{
    $store = app(Stores::class)->create(new CreateStore('main', 'Main', Currency::of('RUB')));

    return app(Channels::class)->create(new CreateChannel($store->id, 'web', 'Web', [Currency::of('RUB')]))->id;
}

/** @return array{ChannelId, SellableRef, array<string, StockItemId>} a kit: 2 × body + 1 × strap, an untracked manual */
function rtnKit(): array
{
    $channel = rtnChannel();
    $kit = SellableRef::of('variant', 'kit');
    $items = [];

    foreach (['body' => [2, StockPolicy::Tracked], 'strap' => [1, StockPolicy::Backorder], 'manual' => [1, StockPolicy::Untracked]] as $sku => [$qpu, $policy]) {
        $items[$sku] = Inventory::items()->create(new CreateStockItem($sku, policy: $policy))->id;
        Inventory::items()->link(new LinkSellable($kit, $items[$sku], $qpu));
    }

    return [$channel, $kit, $items];
}

function rtnOnHand(StockItemId $item, string $location): int
{
    return Inventory::stock()->level($item, Inventory::locations()->findByCode($location)->id)->onHand;
}

it('is the StockReturns port implementation', function (): void {
    expect(app(StockReturns::class))->toBeInstanceOf(DatabaseStockReturns::class);
});

it('restocks a kit into the given location by quantity per unit', function (): void {
    [$channel, $kit, $items] = rtnKit();
    Inventory::locations()->create(new CreateLocation('returns', 'Returns'));

    app(StockReturns::class)->restock(new RestockStock('return:1', $channel, [new RestockLine($kit, 3)], 'returns'));

    $moves = Inventory::movements()->list(new ListMovements(reference: 'return:1'))->items;

    expect(rtnOnHand($items['body'], 'returns'))->toBe(6)
        ->and(rtnOnHand($items['strap'], 'returns'))->toBe(3)
        ->and($moves)->toHaveCount(2)
        ->and(array_map(fn (MovementView $m) => $m->kind, $moves))->toBe([MovementKind::Return, MovementKind::Return])
        ->and(DB::table('cmz_stock_levels')->where('stock_item_id', $items['manual']->value)->exists())->toBeFalse();
});

it('restocks into the first active source of the channel without a location', function (): void {
    [$channel, $kit, $items] = rtnKit();
    $low = Inventory::locations()->create(new CreateLocation('low', 'Low priority'));
    $high = Inventory::locations()->create(new CreateLocation('high', 'High priority'));
    $closed = Inventory::locations()->create(new CreateLocation('closed', 'Closed'));
    Inventory::sources()->assign(new AssignSource($channel, $low->id, 20));
    Inventory::sources()->assign(new AssignSource($channel, $high->id, 10));
    Inventory::sources()->assign(new AssignSource($channel, $closed->id, 1));
    Inventory::locations()->archive(new ArchiveLocation($closed->id));

    app(StockReturns::class)->restock(new RestockStock('return:2', $channel, [new RestockLine($kit, 1)]));

    expect(rtnOnHand($items['body'], 'high'))->toBe(2)
        ->and(rtnOnHand($items['body'], 'low'))->toBe(0);
});

it('restocks a reference once and conflicts on another payload under it', function (): void {
    [$channel, $kit, $items] = rtnKit();
    Inventory::locations()->create(new CreateLocation('returns', 'Returns'));
    $command = new RestockStock('return:3', $channel, [new RestockLine($kit, 1)], 'returns');

    app(StockReturns::class)->restock($command);
    app(StockReturns::class)->restock($command);

    expect(rtnOnHand($items['body'], 'returns'))->toBe(2)
        ->and(Inventory::movements()->list(new ListMovements(reference: 'return:3'))->items)->toHaveCount(2)
        ->and(fn () => app(StockReturns::class)->restock(new RestockStock('return:3', $channel, [new RestockLine($kit, 2)], 'returns')))
        ->toThrow(IdempotencyConflict::class);
});

it('fails on an unlinked sellable unless unlinked sellables are unlimited', function (): void {
    [$channel, $kit, $items] = rtnKit();
    Inventory::locations()->create(new CreateLocation('returns', 'Returns'));
    $loose = SellableRef::of('variant', 'loose');

    expect(fn () => app(StockReturns::class)->restock(new RestockStock('return:4', $channel, [new RestockLine($kit, 1), new RestockLine($loose, 1)], 'returns')))
        ->toThrow(fn (StockLinkMissing $e) => expect($e->code())->toBe('inventory.stock_link_missing'))
        ->and(rtnOnHand($items['body'], 'returns'))->toBe(0);
});

it('skips an unlinked sellable when unlinked sellables are unlimited', function (): void {
    config(['commerz-inventory.unlinked_sellables' => 'unlimited']);
    app()->forgetInstance(InventoryOptions::class);
    app()->forgetInstance(StockReturns::class);
    [$channel, $kit, $items] = rtnKit();
    Inventory::locations()->create(new CreateLocation('returns', 'Returns'));

    app(StockReturns::class)->restock(new RestockStock('return:5', $channel, [new RestockLine($kit, 1), new RestockLine(SellableRef::of('variant', 'loose'), 1)], 'returns'));

    expect(rtnOnHand($items['body'], 'returns'))->toBe(2);
});

it('refuses unknown or archived locations and channels without a source', function (): void {
    [$channel, $kit] = rtnKit();
    $gone = Inventory::locations()->create(new CreateLocation('gone', 'Gone'));
    Inventory::locations()->archive(new ArchiveLocation($gone->id));

    expect(fn () => app(StockReturns::class)->restock(new RestockStock('return:6', $channel, [new RestockLine($kit, 1)], 'nowhere')))->toThrow(LocationNotFound::class)
        ->and(fn () => app(StockReturns::class)->restock(new RestockStock('return:6', $channel, [new RestockLine($kit, 1)], 'gone')))->toThrow(LocationArchived::class)
        ->and(fn () => app(StockReturns::class)->restock(new RestockStock('return:6', $channel, [new RestockLine($kit, 1)])))
        ->toThrow(fn (InvalidCommand $e) => expect($e->code())->toBe('inventory.no_stock_source'));
});
