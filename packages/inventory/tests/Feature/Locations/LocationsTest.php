<?php

declare(strict_types=1);

use Commerz\Configuration\CommerzConfig;
use Commerz\Errors\InvalidCommand;
use Commerz\Identity\Uuid7;
use Commerz\Inventory\Common\Exceptions\DuplicateCode;
use Commerz\Inventory\Facades\Inventory;
use Commerz\Inventory\Items\Data\CreateStockItem;
use Commerz\Inventory\Locations\Data\ArchiveLocation;
use Commerz\Inventory\Locations\Data\CreateLocation;
use Commerz\Inventory\Locations\Data\ListLocations;
use Commerz\Inventory\Locations\Data\LocationId;
use Commerz\Inventory\Locations\Data\RestoreLocation;
use Commerz\Inventory\Locations\Data\UpdateLocation;
use Commerz\Inventory\Locations\Enums\LocationKind;
use Commerz\Inventory\Locations\Enums\LocationStatus;
use Commerz\Inventory\Locations\Exceptions\LocationArchived;
use Commerz\Inventory\Locations\Exceptions\LocationNotEmpty;
use Commerz\Inventory\Locations\Exceptions\LocationNotFound;
use Commerz\Schema\TableNames;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function locDb(): ConnectionInterface
{
    return app(ConnectionResolverInterface::class)->connection(app(CommerzConfig::class)->connection);
}

it('creates, reads and lists locations by code', function (): void {
    $main = Inventory::locations()->create(new CreateLocation('main', 'Main warehouse'));
    Inventory::locations()->create(new CreateLocation('a-shop', 'Shop', LocationKind::Store));

    expect($main->kind)->toBe(LocationKind::Warehouse)
        ->and($main->status)->toBe(LocationStatus::Active)
        ->and(Inventory::locations()->find($main->id)->code)->toBe('main')
        ->and(Inventory::locations()->findByCode('a-shop')->kind)->toBe(LocationKind::Store);

    $first = Inventory::locations()->list(new ListLocations(first: 1));
    $second = Inventory::locations()->list(new ListLocations(first: 1, after: $first->next));

    expect($first->items[0]->code)->toBe('a-shop')
        ->and($first->hasMore())->toBeTrue()
        ->and($second->items[0]->code)->toBe('main')
        ->and($second->hasMore())->toBeFalse();
});

it('rejects a duplicate code and keeps the caller transaction usable', function (): void {
    Inventory::locations()->create(new CreateLocation('main', 'Main'));

    DB::transaction(function (): void {
        expect(fn () => Inventory::locations()->create(new CreateLocation('main', 'Again')))->toThrow(DuplicateCode::class);
        expect(Inventory::locations()->findByCode('main')->name)->toBe('Main');
    });
});

it('validates codes and names', function (): void {
    expect(fn () => new CreateLocation('Bad Code', 'x'))->toThrow(InvalidCommand::class)
        ->and(fn () => new CreateLocation('ok', ' '))->toThrow(InvalidCommand::class)
        ->and(fn () => new ListLocations(first: 0))->toThrow(InvalidCommand::class);
});

it('updates name and kind but not an archived location', function (): void {
    $location = Inventory::locations()->create(new CreateLocation('main', 'Main'));
    $updated = Inventory::locations()->update(new UpdateLocation($location->id, 'Central', LocationKind::PickupPoint));

    expect($updated->name)->toBe('Central')->and($updated->kind)->toBe(LocationKind::PickupPoint);

    Inventory::locations()->archive(new ArchiveLocation($location->id));

    expect(fn () => Inventory::locations()->update(new UpdateLocation($location->id, 'X')))->toThrow(LocationArchived::class);
});

it('archives and restores idempotently and filters lists by status', function (): void {
    $location = Inventory::locations()->create(new CreateLocation('main', 'Main'));

    expect(Inventory::locations()->archive(new ArchiveLocation($location->id))->status)->toBe(LocationStatus::Archived)
        ->and(Inventory::locations()->archive(new ArchiveLocation($location->id))->status)->toBe(LocationStatus::Archived)
        ->and(Inventory::locations()->list(new ListLocations(LocationStatus::Active))->items)->toBe([])
        ->and(Inventory::locations()->restore(new RestoreLocation($location->id))->status)->toBe(LocationStatus::Active)
        ->and(Inventory::locations()->restore(new RestoreLocation($location->id))->status)->toBe(LocationStatus::Active);
});

it('refuses to archive a location with stock, reservations or an open transfer', function (): void {
    $tables = app(TableNames::class);
    $db = locDb();
    $main = Inventory::locations()->create(new CreateLocation('main', 'Main'));
    $other = Inventory::locations()->create(new CreateLocation('other', 'Other'));
    $item = Inventory::items()->create(new CreateStockItem('sku-1'));

    $level = fn (array $values) => $db->table($tables->table('stock_levels'))->updateOrInsert(
        ['stock_item_id' => $item->id->value, 'location_id' => $main->id->value],
        $values + ['id' => Uuid7::generate(), 'created_at' => now(), 'updated_at' => now()],
    );

    $level(['on_hand' => 3]);
    expect(fn () => Inventory::locations()->archive(new ArchiveLocation($main->id)))->toThrow(LocationNotEmpty::class);

    $level(['on_hand' => 0, 'reserved' => 0]);
    expect(Inventory::locations()->archive(new ArchiveLocation($main->id))->status)->toBe(LocationStatus::Archived);
    Inventory::locations()->restore(new RestoreLocation($main->id));

    $db->table($tables->table('stock_transfers'))->insert([
        'id' => Uuid7::generate(), 'from_location_id' => $main->id->value, 'to_location_id' => $other->id->value,
        'state' => 'in_transit', 'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(fn () => Inventory::locations()->archive(new ArchiveLocation($other->id)))->toThrow(LocationNotEmpty::class)
        ->and(fn () => Inventory::locations()->archive(new ArchiveLocation($main->id)))->toThrow(LocationNotEmpty::class);
});

it('reports a missing location', function (): void {
    $missing = LocationId::generate();

    expect(fn () => Inventory::locations()->find($missing))->toThrow(LocationNotFound::class)
        ->and(fn () => Inventory::locations()->findByCode('nope'))->toThrow(LocationNotFound::class)
        ->and(fn () => Inventory::locations()->archive(new ArchiveLocation($missing)))->toThrow(LocationNotFound::class);
});
