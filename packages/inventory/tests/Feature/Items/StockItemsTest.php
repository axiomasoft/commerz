<?php

declare(strict_types=1);

use Commerz\Configuration\CommerzOptions;
use Commerz\Errors\InvalidCommand;
use Commerz\Identity\SellableRef;
use Commerz\Identity\Uuid7;
use Commerz\Inventory\Common\Exceptions\DuplicateCode;
use Commerz\Inventory\Facades\Inventory;
use Commerz\Inventory\Items\Data\ArchiveStockItem;
use Commerz\Inventory\Items\Data\CreateStockItem;
use Commerz\Inventory\Items\Data\LinkSellable;
use Commerz\Inventory\Items\Data\ListLinks;
use Commerz\Inventory\Items\Data\ListStockItems;
use Commerz\Inventory\Items\Data\RestoreStockItem;
use Commerz\Inventory\Items\Data\StockItemId;
use Commerz\Inventory\Items\Data\UnlinkSellable;
use Commerz\Inventory\Items\Data\UpdateStockItem;
use Commerz\Inventory\Items\Enums\StockItemStatus;
use Commerz\Inventory\Items\Enums\StockPolicy;
use Commerz\Inventory\Items\Exceptions\StockItemArchived;
use Commerz\Inventory\Items\Exceptions\StockItemNotEmpty;
use Commerz\Inventory\Items\Exceptions\StockItemNotFound;
use Commerz\Inventory\Items\Exceptions\TooManyComponents;
use Commerz\Inventory\Items\Exceptions\UntrackedStockItem;
use Commerz\Inventory\Locations\Data\CreateLocation;
use Commerz\Pagination\Cursor;
use Commerz\Schema\TableNames;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function sellable(string $id): SellableRef
{
    return SellableRef::of('variant', $id);
}

/** Puts stock straight into a level: the ledger arrives with P3.4. */
function itemHolds(StockItemId $item, int $onHand, int $reserved = 0): void
{
    $location = Inventory::locations()->findByCode('main');
    $db = app(ConnectionResolverInterface::class)->connection(app(CommerzOptions::class)->connection);
    $db->table(app(TableNames::class)->table('stock_levels'))->insert([
        'id' => Uuid7::generate(), 'stock_item_id' => $item->value, 'location_id' => $location->id->value,
        'on_hand' => $onHand, 'reserved' => $reserved, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

it('creates items with defaults and reads them by id and SKU', function (): void {
    $item = Inventory::items()->create(new CreateStockItem('tee-m'));

    expect($item->unit)->toBe('pcs')
        ->and($item->policy)->toBe(StockPolicy::Tracked)
        ->and($item->status)->toBe(StockItemStatus::Active)
        ->and($item->name)->toBeNull()
        ->and(Inventory::items()->find($item->id)->sku)->toBe('tee-m')
        ->and(Inventory::items()->findBySku('tee-m')->id->equals($item->id))->toBeTrue();
});

it('rejects a duplicate SKU, bad input and unknown items', function (): void {
    Inventory::items()->create(new CreateStockItem('tee-m'));

    expect(fn () => Inventory::items()->create(new CreateStockItem('tee-m')))->toThrow(DuplicateCode::class)
        ->and(fn () => new CreateStockItem('has space'))->toThrow(InvalidCommand::class)
        ->and(fn () => new CreateStockItem('x', unit: 'Bad Unit'))->toThrow(InvalidCommand::class)
        ->and(fn () => Inventory::items()->findBySku('nope'))->toThrow(StockItemNotFound::class)
        ->and(fn () => Inventory::items()->find(StockItemId::generate()))->toThrow(StockItemNotFound::class);
});

it('lists items by SKU with a cursor', function (): void {
    foreach (['c', 'a', 'b'] as $sku) {
        Inventory::items()->create(new CreateStockItem($sku));
    }

    $first = Inventory::items()->list(new ListStockItems(first: 2));
    $second = Inventory::items()->list(new ListStockItems(first: 2, after: $first->next));

    expect(array_map(fn (object $i) => $i->sku, $first->items))->toBe(['a', 'b'])
        ->and(array_map(fn (object $i) => $i->sku, $second->items))->toBe(['c'])
        ->and($second->hasMore())->toBeFalse();
});

it('updates name and policy but never the unit', function (): void {
    $item = Inventory::items()->create(new CreateStockItem('rope', unit: 'm'));
    $updated = Inventory::items()->update(new UpdateStockItem($item->id, 'Rope', StockPolicy::Backorder));

    expect($updated->name)->toBe('Rope')
        ->and($updated->policy)->toBe(StockPolicy::Backorder)
        ->and($updated->unit)->toBe('m')
        ->and(new ReflectionClass(UpdateStockItem::class)->hasProperty('unit'))->toBeFalse();
});

it('refuses to untrack an item that holds stock but allows it when levels are empty', function (): void {
    Inventory::locations()->create(new CreateLocation('main', 'Main'));
    $item = Inventory::items()->create(new CreateStockItem('tee'));
    itemHolds($item->id, 4);

    expect(fn () => Inventory::items()->update(new UpdateStockItem($item->id, policy: StockPolicy::Untracked)))
        ->toThrow(UntrackedStockItem::class);

    $empty = Inventory::items()->create(new CreateStockItem('air'));

    expect(Inventory::items()->update(new UpdateStockItem($empty->id, policy: StockPolicy::Untracked))->policy)->toBe(StockPolicy::Untracked);
});

it('archives and restores idempotently and refuses a non-empty item', function (): void {
    Inventory::locations()->create(new CreateLocation('main', 'Main'));
    $item = Inventory::items()->create(new CreateStockItem('tee'));

    expect(Inventory::items()->archive(new ArchiveStockItem($item->id))->status)->toBe(StockItemStatus::Archived)
        ->and(Inventory::items()->archive(new ArchiveStockItem($item->id))->status)->toBe(StockItemStatus::Archived)
        ->and(fn () => Inventory::items()->update(new UpdateStockItem($item->id, 'X')))->toThrow(StockItemArchived::class)
        ->and(Inventory::items()->list(new ListStockItems(StockItemStatus::Active))->items)->toBe([])
        ->and(Inventory::items()->restore(new RestoreStockItem($item->id))->status)->toBe(StockItemStatus::Active);

    itemHolds($item->id, 1);

    expect(fn () => Inventory::items()->archive(new ArchiveStockItem($item->id)))->toThrow(StockItemNotEmpty::class);
});

it('links a sellable to items, upserting the quantity per unit', function (): void {
    $item = Inventory::items()->create(new CreateStockItem('tee'));
    $link = Inventory::items()->link(new LinkSellable(sellable('v1'), $item->id));
    $again = Inventory::items()->link(new LinkSellable(sellable('v1'), $item->id, 3));

    expect($link->quantityPerUnit)->toBe(1)
        ->and($again->quantityPerUnit)->toBe(3)
        ->and($again->id)->toBe($link->id)
        ->and($again->sku)->toBe('tee')
        ->and((string) $again->sellable)->toBe('variant:v1')
        ->and(Inventory::items()->links(new ListLinks(sellable: sellable('v1')))->items)->toHaveCount(1)
        ->and(fn () => new LinkSellable(sellable('v1'), $item->id, 0))->toThrow(InvalidCommand::class)
        ->and(fn () => new ListLinks)->toThrow(InvalidCommand::class);
});

it('models a kit and a shared component and lists links by sellable and by item', function (): void {
    $box = Inventory::items()->create(new CreateStockItem('box'));
    $cup = Inventory::items()->create(new CreateStockItem('cup'));

    Inventory::items()->link(new LinkSellable(sellable('kit'), $box->id));
    Inventory::items()->link(new LinkSellable(sellable('kit'), $cup->id, 2));
    Inventory::items()->link(new LinkSellable(sellable('single'), $cup->id));

    $kit = Inventory::items()->links(new ListLinks(sellable: sellable('kit')))->items;
    $ofCup = Inventory::items()->links(new ListLinks(item: $cup->id))->items;
    $pair = Inventory::items()->links(new ListLinks(sellable('kit'), $cup->id))->items;

    expect($kit)->toHaveCount(2)
        ->and($ofCup)->toHaveCount(2)
        ->and($pair)->toHaveCount(1)
        ->and($pair[0]->quantityPerUnit)->toBe(2);

    $page = Inventory::items()->links(new ListLinks(item: $cup->id, first: 1));
    $rest = Inventory::items()->links(new ListLinks(item: $cup->id, first: 1, after: $page->next));

    expect($page->hasMore())->toBeTrue()
        ->and($rest->items)->toHaveCount(1)
        ->and($rest->hasMore())->toBeFalse()
        ->and($rest->items[0]->id)->not->toBe($page->items[0]->id)
        ->and(fn () => Inventory::items()->links(new ListLinks(item: $cup->id, after: Cursor::after('not-a-uuid'))))
        ->toThrow(InvalidCommand::class);
});

it('allows at most 20 components per sellable but still updates an existing one', function (): void {
    $first = null;

    foreach (range(1, 20) as $n) {
        $item = Inventory::items()->create(new CreateStockItem("part-{$n}"));
        $first ??= $item;
        Inventory::items()->link(new LinkSellable(sellable('big'), $item->id));
    }

    $extra = Inventory::items()->create(new CreateStockItem('part-21'));

    expect(fn () => Inventory::items()->link(new LinkSellable(sellable('big'), $extra->id)))->toThrow(TooManyComponents::class)
        ->and(Inventory::items()->link(new LinkSellable(sellable('big'), $first->id, 5))->quantityPerUnit)->toBe(5)
        ->and(Inventory::items()->link(new LinkSellable(sellable('other'), $extra->id))->quantityPerUnit)->toBe(1);
});

it('unlinks idempotently and refuses to link an archived item', function (): void {
    $item = Inventory::items()->create(new CreateStockItem('tee'));
    Inventory::items()->link(new LinkSellable(sellable('v1'), $item->id));

    Inventory::items()->unlink(new UnlinkSellable(sellable('v1'), $item->id));
    Inventory::items()->unlink(new UnlinkSellable(sellable('v1'), $item->id));

    expect(Inventory::items()->links(new ListLinks(sellable: sellable('v1')))->items)->toBe([]);

    Inventory::items()->archive(new ArchiveStockItem($item->id));

    expect(fn () => Inventory::items()->link(new LinkSellable(sellable('v1'), $item->id)))->toThrow(StockItemArchived::class)
        ->and(fn () => Inventory::items()->link(new LinkSellable(sellable('v1'), StockItemId::generate())))->toThrow(StockItemNotFound::class);
});

it('stores and updates optional customs attributes on stock items', function (): void {
    $plain = Inventory::items()->create(new CreateStockItem('plain-tee'));

    expect($plain->hsCode)->toBeNull()
        ->and($plain->originCountryCode)->toBeNull()
        ->and($plain->material)->toBeNull()
        ->and($plain->customsDescription)->toBeNull();

    $created = Inventory::items()->create(new CreateStockItem(
        'cotton-tee',
        name: 'Cotton Tee',
        hsCode: '6109.10.00',
        originCountryCode: 'CN',
        material: '100% Cotton',
        customsDescription: 'Knitted cotton t-shirt',
    ));

    expect($created->hsCode)->toBe('6109.10.00')
        ->and($created->originCountryCode)->toBe('CN')
        ->and($created->material)->toBe('100% Cotton')
        ->and($created->customsDescription)->toBe('Knitted cotton t-shirt')
        ->and(Inventory::items()->findBySku('cotton-tee')->hsCode)->toBe('6109.10.00');

    $updated = Inventory::items()->update(new UpdateStockItem(
        $created->id,
        hsCode: '6109.90.00',
        originCountryCode: 'TR',
    ));

    expect($updated->hsCode)->toBe('6109.90.00')
        ->and($updated->originCountryCode)->toBe('TR')
        ->and($updated->material)->toBe('100% Cotton')
        ->and($updated->customsDescription)->toBe('Knitted cotton t-shirt')
        ->and(fn () => new CreateStockItem('bad', originCountryCode: 'cn'))->toThrow(InvalidCommand::class)
        ->and(fn () => new CreateStockItem('bad', hsCode: ' '))->toThrow(InvalidCommand::class)
        ->and(fn () => new UpdateStockItem($created->id, originCountryCode: 'USA'))->toThrow(InvalidCommand::class);
});
