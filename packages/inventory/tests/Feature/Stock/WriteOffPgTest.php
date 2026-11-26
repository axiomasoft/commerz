<?php

declare(strict_types=1);

use Commerz\Identity\SellableRef;
use Commerz\Inventory\Facades\Inventory;
use Commerz\Inventory\Internal\Reconciler;
use Commerz\Inventory\Items\Data\CreateStockItem;
use Commerz\Inventory\Items\Data\LinkSellable;
use Commerz\Inventory\Items\Enums\StockPolicy;
use Commerz\Inventory\Locations\Data\CreateLocation;
use Commerz\Inventory\Movements\Data\ListMovements;
use Commerz\Inventory\Sources\Data\AssignSource;
use Commerz\Inventory\Stock\Data\AdjustStock;
use Commerz\Inventory\Stock\Data\ReceiveStock;
use Commerz\Inventory\Stock\Enums\MovementKind;
use Commerz\Money\Currency;
use Commerz\Ports\Stock\Contracts\StockReturns;
use Commerz\Ports\Stock\Data\WriteOffLine;
use Commerz\Ports\Stock\Data\WriteOffStock;
use Commerz\Stores\Contracts\Channels;
use Commerz\Stores\Contracts\Stores;
use Commerz\Stores\Data\CreateChannel;
use Commerz\Stores\Data\CreateStore;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('enforces INV-INV-002 and keeps Reconciler clean on PostgreSQL after write_off', function (): void {
    $store = app(Stores::class)->create(new CreateStore('pg-main', 'PG Main', Currency::of('RUB')));
    $channel = app(Channels::class)->create(new CreateChannel($store->id, 'pg-web', 'PG Web', [Currency::of('RUB')]))->id;

    $wh = Inventory::locations()->create(new CreateLocation('pg-wh', 'PG Warehouse'));
    Inventory::sources()->assign(new AssignSource($channel, $wh->id, 10));

    $sellable = SellableRef::of('variant', 'vase');
    $item = Inventory::items()->create(new CreateStockItem('SKU-VASE', policy: StockPolicy::Tracked));
    Inventory::items()->link(new LinkSellable($sellable, $item->id, 1));

    Inventory::stock()->receive(new ReceiveStock($item->id, $wh->id, 10));
    Inventory::stock()->adjust(new AdjustStock($item->id, $wh->id, -2, 'sold'));

    app(StockReturns::class)->writeOff(new WriteOffStock(
        'return:pg-1:write_off',
        $channel,
        [new WriteOffLine($sellable, 2)],
        'pg-wh',
        'damaged_in_transit',
    ));

    expect(Inventory::stock()->level($item->id, $wh->id)->onHand)->toBe(8)
        ->and(Inventory::movements()->list(new ListMovements(reference: 'return:pg-1:write_off'))->items[0]->kind)->toBe(MovementKind::WriteOff)
        ->and(app(Reconciler::class)->check()->discrepancies)->toBe([]);
});
