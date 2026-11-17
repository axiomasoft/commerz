<?php

declare(strict_types=1);

use Commerz\Errors\InvalidCommand;
use Commerz\Inventory\Facades\Inventory;
use Commerz\Inventory\Locations\Data\ArchiveLocation;
use Commerz\Inventory\Locations\Data\CreateLocation;
use Commerz\Inventory\Locations\Data\LocationId;
use Commerz\Inventory\Locations\Exceptions\LocationArchived;
use Commerz\Inventory\Locations\Exceptions\LocationNotFound;
use Commerz\Inventory\Sources\Data\AssignSource;
use Commerz\Inventory\Sources\Data\StockSourceView;
use Commerz\Inventory\Sources\Data\UnassignSource;
use Commerz\Money\Currency;
use Commerz\Stores\Contracts\Channels;
use Commerz\Stores\Contracts\Stores;
use Commerz\Stores\Data\ChannelId;
use Commerz\Stores\Data\CreateChannel;
use Commerz\Stores\Data\CreateStore;
use Commerz\Stores\Data\ListStores;
use Commerz\Stores\Exceptions\ChannelNotFound;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function srcChannel(string $code = 'web'): ChannelId
{
    $stores = app(Stores::class);
    $store = $stores->list(new ListStores)->items[0] ?? $stores->create(new CreateStore('main', 'Main', Currency::of('RUB')));

    return app(Channels::class)->create(new CreateChannel($store->id, $code, ucfirst($code), [Currency::of('RUB')]))->id;
}

it('lists a channel sources by priority and then by location code', function (): void {
    $channel = srcChannel();
    $b = Inventory::locations()->create(new CreateLocation('b', 'B'));
    $a = Inventory::locations()->create(new CreateLocation('a', 'A'));
    $c = Inventory::locations()->create(new CreateLocation('c', 'C'));

    Inventory::sources()->assign(new AssignSource($channel, $b->id, 10));
    Inventory::sources()->assign(new AssignSource($channel, $c->id, 5));
    Inventory::sources()->assign(new AssignSource($channel, $a->id, 10));

    $list = Inventory::sources()->list($channel);

    expect(array_map(fn (StockSourceView $s) => $s->locationCode, $list))->toBe(['c', 'a', 'b'])
        ->and(array_map(fn (StockSourceView $s) => $s->priority, $list))->toBe([5, 10, 10])
        ->and($list[0]->channel->equals($channel))->toBeTrue()
        ->and($list[0]->location->equals($c->id))->toBeTrue();
});

it('upserts the priority of an existing pair and keeps channels apart', function (): void {
    $web = srcChannel('web');
    $pos = srcChannel('pos');
    $main = Inventory::locations()->create(new CreateLocation('main', 'Main'));

    Inventory::sources()->assign(new AssignSource($web, $main->id));
    $view = Inventory::sources()->assign(new AssignSource($web, $main->id, 7));
    Inventory::sources()->assign(new AssignSource($pos, $main->id, 1));

    expect($view->priority)->toBe(7)
        ->and(Inventory::sources()->list($web))->toHaveCount(1)
        ->and(Inventory::sources()->list($web)[0]->priority)->toBe(7)
        ->and(Inventory::sources()->list($pos)[0]->priority)->toBe(1);
});

it('unassigns idempotently', function (): void {
    $channel = srcChannel();
    $main = Inventory::locations()->create(new CreateLocation('main', 'Main'));
    Inventory::sources()->assign(new AssignSource($channel, $main->id));

    Inventory::sources()->unassign(new UnassignSource($channel, $main->id));
    Inventory::sources()->unassign(new UnassignSource($channel, $main->id));

    expect(Inventory::sources()->list($channel))->toBe([]);
});

it('fails for a missing channel, a missing or archived location and a bad priority', function (): void {
    $channel = srcChannel();
    $main = Inventory::locations()->create(new CreateLocation('main', 'Main'));

    expect(fn () => Inventory::sources()->assign(new AssignSource(ChannelId::generate(), $main->id)))->toThrow(ChannelNotFound::class)
        ->and(fn () => Inventory::sources()->list(ChannelId::generate()))->toThrow(ChannelNotFound::class)
        ->and(fn () => Inventory::sources()->assign(new AssignSource($channel, LocationId::generate())))->toThrow(LocationNotFound::class)
        ->and(fn () => new AssignSource($channel, $main->id, -1))->toThrow(InvalidCommand::class)
        ->and(fn () => new AssignSource($channel, $main->id, 1_000_001))->toThrow(InvalidCommand::class);

    Inventory::locations()->archive(new ArchiveLocation($main->id));

    expect(fn () => Inventory::sources()->assign(new AssignSource($channel, $main->id)))->toThrow(LocationArchived::class);
});
