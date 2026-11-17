<?php

declare(strict_types=1);

// Shared fixtures of the reservation and availability tests.

use Commerz\Identity\SellableRef;
use Commerz\Inventory\Configuration\InventoryOptions;
use Commerz\Inventory\Facades\Inventory;
use Commerz\Inventory\Items\Data\CreateStockItem;
use Commerz\Inventory\Items\Data\LinkSellable;
use Commerz\Inventory\Items\Data\StockItemId;
use Commerz\Inventory\Items\Enums\StockPolicy;
use Commerz\Inventory\Locations\Data\CreateLocation;
use Commerz\Inventory\Locations\Data\LocationId;
use Commerz\Inventory\Reservations\Contracts\Reservations;
use Commerz\Inventory\Reservations\Internal\DatabaseReservations;
use Commerz\Inventory\Sources\Data\AssignSource;
use Commerz\Inventory\Stock\Data\ReceiveStock;
use Commerz\Inventory\Stock\Data\StockLevelView;
use Commerz\Inventory\Transfers\Contracts\Transfers;
use Commerz\Money\Currency;
use Commerz\Ports\Stock\Contracts\StockAvailability;
use Commerz\Ports\Stock\Contracts\StockReservations;
use Commerz\Ports\Stock\Data\ReservationResult;
use Commerz\Ports\Stock\Data\ReserveLine;
use Commerz\Ports\Stock\Data\ReserveStock;
use Commerz\Ports\Stock\Data\StockAllocation;
use Commerz\Stores\Contracts\Channels;
use Commerz\Stores\Contracts\Stores;
use Commerz\Stores\Data\ChannelId;
use Commerz\Stores\Data\CreateChannel;
use Commerz\Stores\Data\CreateStore;
use Commerz\Stores\Data\StoreId;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;

function rsvChannel(string $code = 'web'): ChannelId
{
    $store = DB::table('cmz_stores')->where('code', 'main')->value('id')
        ?? app(Stores::class)->create(new CreateStore('main', 'Main', Currency::of('RUB')))->id->value;

    return app(Channels::class)->create(new CreateChannel(new StoreId($store), $code, ucfirst($code), [Currency::of('RUB')]))->id;
}

function rsvSource(ChannelId $channel, string $code, int $priority = 100): LocationId
{
    $location = Inventory::locations()->create(new CreateLocation($code, ucfirst($code)))->id;
    Inventory::sources()->assign(new AssignSource($channel, $location, $priority));

    return $location;
}

/** @param  array<string, int>  $links  sellable id => quantity per unit */
function rsvItem(string $sku, array $links, StockPolicy $policy = StockPolicy::Tracked): StockItemId
{
    $item = Inventory::items()->create(new CreateStockItem($sku, policy: $policy))->id;

    foreach ($links as $sellable => $quantityPerUnit) {
        Inventory::items()->link(new LinkSellable(SellableRef::of('variant', $sellable), $item, $quantityPerUnit));
    }

    return $item;
}

function rsvReceive(StockItemId $item, LocationId $location, int $quantity): void
{
    Inventory::stock()->receive(new ReceiveStock($item, $location, $quantity));
}

/** @param  array<string, int>  $lines  sellable id => units */
function rsvReserve(ChannelId $channel, string $demand, array $lines, ?DateTimeImmutable $expiresAt = null): ReservationResult
{
    $reserve = [];

    foreach ($lines as $sellable => $units) {
        $reserve[] = new ReserveLine(SellableRef::of('variant', $sellable), $units);
    }

    return Inventory::reservations()->reserve(new ReserveStock($demand, $channel, $reserve, $expiresAt));
}

function rsvLevel(StockItemId $item, LocationId $location): StockLevelView
{
    return Inventory::stock()->level($item, $location);
}

/** @return array<string, list<array{string, int}>> sellable => [location code, units] */
function rsvHeld(ReservationResult $result): array
{
    $held = [];

    foreach ($result->lines as $line) {
        $held[$line->sellable->id] = array_map(static fn (StockAllocation $a): array => [$a->locationCode, $a->quantity], $line->allocations);
    }

    return $held;
}

/** @param  array<string, mixed>  $config */
function rsvConfigure(array $config): void
{
    config(collect($config)->mapWithKeys(fn ($value, $key) => ["commerz-inventory.{$key}" => $value])->all());

    foreach ([InventoryOptions::class, DatabaseReservations::class, Reservations::class, StockReservations::class, StockAvailability::class, Transfers::class, Commerz\Inventory\Inventory::class] as $abstract) {
        app()->forgetInstance($abstract);
    }

    Facade::clearResolvedInstances();
}

function rsvLines(): int
{
    return DB::table('cmz_stock_reservation_lines')->count();
}
