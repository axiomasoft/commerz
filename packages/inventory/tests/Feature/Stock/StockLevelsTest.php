<?php

declare(strict_types=1);

use Commerz\Errors\IdempotencyConflict;
use Commerz\Errors\InvalidCommand;
use Commerz\Identity\ActorRef;
use Commerz\Identity\IdempotencyKey;
use Commerz\Inventory\Common\Internal\LevelRow;
use Commerz\Inventory\Common\Internal\StockLedger;
use Commerz\Inventory\Facades\Inventory;
use Commerz\Inventory\Items\Data\ArchiveStockItem;
use Commerz\Inventory\Items\Data\CreateStockItem;
use Commerz\Inventory\Items\Data\StockItemId;
use Commerz\Inventory\Items\Enums\StockPolicy;
use Commerz\Inventory\Items\Exceptions\StockItemArchived;
use Commerz\Inventory\Items\Exceptions\StockItemNotFound;
use Commerz\Inventory\Items\Exceptions\UntrackedStockItem;
use Commerz\Inventory\Locations\Data\ArchiveLocation;
use Commerz\Inventory\Locations\Data\CreateLocation;
use Commerz\Inventory\Locations\Data\LocationId;
use Commerz\Inventory\Locations\Exceptions\LocationArchived;
use Commerz\Inventory\Locations\Exceptions\LocationNotFound;
use Commerz\Inventory\Movements\Data\ListMovements;
use Commerz\Inventory\Stock\Data\AdjustStock;
use Commerz\Inventory\Stock\Data\CountStock;
use Commerz\Inventory\Stock\Data\ListStockLevels;
use Commerz\Inventory\Stock\Data\ReceiveStock;
use Commerz\Inventory\Stock\Data\SetSafetyStock;
use Commerz\Inventory\Stock\Enums\MovementKind;
use Commerz\Inventory\Stock\Events\StockLevelChanged;
use Commerz\Inventory\Stock\Events\StockShortfall;
use Commerz\Inventory\Stock\Exceptions\NegativeStock;
use Commerz\Inventory\Stock\Exceptions\StaleStockCount;
use Commerz\Operations\Contracts\Transactions;
use Commerz\Pagination\Cursor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

/** @return array{StockItemId, LocationId} */
function lvlPair(string $sku = 'tee', StockPolicy $policy = StockPolicy::Tracked, string $location = 'main'): array
{
    $item = Inventory::items()->create(new CreateStockItem($sku, policy: $policy));
    $at = Inventory::locations()->create(new CreateLocation($location, ucfirst($location)));

    return [$item->id, $at->id];
}

/** Reserves units the way the reservations module will: through the ledger, in a transaction. */
function lvlHold(StockItemId $item, LocationId $location, int $quantity): void
{
    app(Transactions::class)->run(function () use ($item, $location, $quantity): void {
        $ledger = app(StockLedger::class);
        $ledger->within(function () use ($ledger, $item, $location, $quantity): void {
            $pair = [$item->value, $location->value];
            $ledger->ensureLevels([$pair]);
            $ledger->hold($ledger->lock([$pair])[LevelRow::key(...$pair)], $quantity, false);
        });
    });
}

/** @return list<array{kind: string, delta: int, balance_after: int}> */
function lvlJournal(StockItemId $item): array
{
    $journal = [];

    foreach (DB::table('cmz_stock_movements')->where('stock_item_id', $item->value)->orderBy('id')->get(['kind', 'delta', 'balance_after']) as $row) {
        $values = get_object_vars($row);
        $kind = $values['kind'] ?? null;
        $delta = $values['delta'] ?? null;
        $balance = $values['balance_after'] ?? null;

        if (! is_string($kind) || ! is_int($delta) || ! is_int($balance)) {
            throw new RuntimeException('unexpected movement row');
        }

        $journal[] = ['kind' => $kind, 'delta' => $delta, 'balance_after' => $balance];
    }

    return $journal;
}

it('receives stock into a new level and journals a receipt', function (): void {
    [$item, $location] = lvlPair();

    $view = Inventory::stock()->receive(new ReceiveStock($item, $location, 5, reference: 'po:1', reason: 'purchase', actor: ActorRef::of('user', '7')));

    expect($view->onHand)->toBe(5)
        ->and($view->reserved)->toBe(0)
        ->and($view->available)->toBe(5)
        ->and($view->version)->toBe(1)
        ->and($view->sku)->toBe('tee')
        ->and($view->locationCode)->toBe('main')
        ->and(lvlJournal($item))->toBe([['kind' => 'receipt', 'delta' => 5, 'balance_after' => 5]]);

    $movement = Inventory::movements()->list(new ListMovements($item))->items[0];

    expect($movement->kind)->toBe(MovementKind::Receipt)
        ->and($movement->reference)->toBe('po:1')
        ->and($movement->reason)->toBe('purchase')
        ->and((string) $movement->actor)->toBe('user:7')
        ->and($movement->locationCode)->toBe('main');
});

it('keeps balance_after sequential over receive, adjust and count', function (): void {
    [$item, $location] = lvlPair();

    Inventory::stock()->receive(new ReceiveStock($item, $location, 10));
    Inventory::stock()->adjust(new AdjustStock($item, $location, -3, 'damage'));
    Inventory::stock()->count(new CountStock($item, $location, 9, expectedOnHand: 7));
    Inventory::stock()->receive(new ReceiveStock($item, $location, 1));

    expect(lvlJournal($item))->toBe([
        ['kind' => 'receipt', 'delta' => 10, 'balance_after' => 10],
        ['kind' => 'adjustment', 'delta' => -3, 'balance_after' => 7],
        ['kind' => 'count', 'delta' => 2, 'balance_after' => 9],
        ['kind' => 'receipt', 'delta' => 1, 'balance_after' => 10],
    ])->and(Inventory::stock()->level($item, $location)->onHand)->toBe(10);
});

it('refuses to take on-hand below zero and writes nothing', function (): void {
    [$item, $location] = lvlPair();
    Inventory::stock()->receive(new ReceiveStock($item, $location, 2));

    expect(fn () => Inventory::stock()->adjust(new AdjustStock($item, $location, -3, 'damage')))
        ->toThrow(fn (NegativeStock $e) => expect([$e->onHand, $e->delta, $e->code()])->toBe([2, -3, 'inventory.negative_stock']));

    expect(Inventory::stock()->level($item, $location)->onHand)->toBe(2)
        ->and(lvlJournal($item))->toHaveCount(1)
        ->and(DB::table('cmz_outbox_messages')->count())->toBe(1);
});

it('lets an adjustment drop below reserved and reports the shortfall', function (): void {
    [$item, $location] = lvlPair();
    Inventory::stock()->receive(new ReceiveStock($item, $location, 5));
    lvlHold($item, $location, 4);
    Event::fake([StockShortfall::class, StockLevelChanged::class]);

    $view = Inventory::stock()->adjust(new AdjustStock($item, $location, -3, 'damage'));

    expect([$view->onHand, $view->reserved, $view->available])->toBe([2, 4, -2]);
    Event::assertDispatched(StockShortfall::class, fn (StockShortfall $e): bool => $e->shortfall === 2 && $e->level->onHand === 2);
    Event::assertDispatched(StockLevelChanged::class, fn (StockLevelChanged $e): bool => $e->kind === MovementKind::Adjustment && $e->delta === -3);

    // Still short, but no shorter: no second signal.
    Inventory::stock()->receive(new ReceiveStock($item, $location, 1));
    Event::assertDispatchedTimes(StockShortfall::class, 1);
});

it('reports a shortfall when safety stock rises above free stock', function (): void {
    [$item, $location] = lvlPair();
    Inventory::stock()->receive(new ReceiveStock($item, $location, 3));
    Event::fake([StockShortfall::class, StockLevelChanged::class]);

    $view = Inventory::stock()->setSafetyStock(new SetSafetyStock($item, $location, 5));

    expect([$view->onHand, $view->safetyStock, $view->available])->toBe([3, 5, -2])
        ->and(lvlJournal($item))->toHaveCount(1);
    Event::assertDispatched(StockShortfall::class, fn (StockShortfall $e): bool => $e->shortfall === 2);
    Event::assertDispatched(StockLevelChanged::class, fn (StockLevelChanged $e): bool => $e->kind === null && $e->delta === 0);
});

it('counts without a movement when nothing changed', function (): void {
    [$item, $location] = lvlPair();
    Inventory::stock()->receive(new ReceiveStock($item, $location, 4));

    $view = Inventory::stock()->count(new CountStock($item, $location, 4, expectedOnHand: 4));

    expect($view->onHand)->toBe(4)
        ->and($view->version)->toBe(1)
        ->and(lvlJournal($item))->toHaveCount(1);
});

it('refuses a count that started from a stale on-hand', function (): void {
    [$item, $location] = lvlPair();
    Inventory::stock()->receive(new ReceiveStock($item, $location, 4));
    Inventory::stock()->adjust(new AdjustStock($item, $location, -1, 'sold at the counter'));

    expect(fn () => Inventory::stock()->count(new CountStock($item, $location, 10, expectedOnHand: 4)))
        ->toThrow(fn (StaleStockCount $e) => expect([$e->expected, $e->actual])->toBe([4, 3]));

    expect(Inventory::stock()->level($item, $location)->onHand)->toBe(3)
        ->and(Inventory::stock()->count(new CountStock($item, $location, 10))->onHand)->toBe(10);
});

it('replays a keyed command once and rejects another payload under the key', function (): void {
    [$item, $location] = lvlPair();
    $key = IdempotencyKey::of('po:1:line:1');

    $first = Inventory::stock()->receive(new ReceiveStock($item, $location, 5, idempotencyKey: $key));
    $again = Inventory::stock()->receive(new ReceiveStock($item, $location, 5, idempotencyKey: $key));

    expect($again)->toEqual($first)
        ->and(lvlJournal($item))->toHaveCount(1)
        ->and(Inventory::stock()->level($item, $location)->onHand)->toBe(5)
        ->and(fn () => Inventory::stock()->receive(new ReceiveStock($item, $location, 6, idempotencyKey: $key)))
        ->toThrow(IdempotencyConflict::class);

    // Scopes are separate: the same key on an adjustment is a new operation.
    Inventory::stock()->adjust(new AdjustStock($item, $location, -1, 'damage', idempotencyKey: $key));
    expect(lvlJournal($item))->toHaveCount(2);
});

it('refuses stock on untracked items', function (): void {
    [$item, $location] = lvlPair(policy: StockPolicy::Untracked);

    expect(fn () => Inventory::stock()->receive(new ReceiveStock($item, $location, 1)))->toThrow(UntrackedStockItem::class)
        ->and(fn () => Inventory::stock()->setSafetyStock(new SetSafetyStock($item, $location, 1)))->toThrow(UntrackedStockItem::class)
        ->and(DB::table('cmz_stock_levels')->count())->toBe(0);
});

it('takes no new stock into an archived location but lets it be emptied', function (): void {
    [$item, $location] = lvlPair();
    Inventory::stock()->receive(new ReceiveStock($item, $location, 3));
    Inventory::stock()->adjust(new AdjustStock($item, $location, -3, 'moved out'));
    Inventory::locations()->archive(new ArchiveLocation($location));

    expect(fn () => Inventory::stock()->receive(new ReceiveStock($item, $location, 1)))->toThrow(LocationArchived::class)
        ->and(fn () => Inventory::stock()->adjust(new AdjustStock($item, $location, 1, 'found')))->toThrow(LocationArchived::class)
        ->and(fn () => Inventory::stock()->count(new CountStock($item, $location, 1)))->toThrow(LocationArchived::class)
        ->and(Inventory::stock()->count(new CountStock($item, $location, 0))->onHand)->toBe(0);
});

it('lets an archived item be counted down but not received', function (): void {
    [$item, $location] = lvlPair();
    Inventory::items()->archive(new ArchiveStockItem($item));

    expect(fn () => Inventory::stock()->receive(new ReceiveStock($item, $location, 1)))->toThrow(StockItemArchived::class)
        ->and(fn () => Inventory::stock()->adjust(new AdjustStock($item, $location, -1, 'x')))->toThrow(NegativeStock::class);
});

it('records one outbox event per changed level and command', function (): void {
    [$item, $location] = lvlPair();
    Inventory::stock()->receive(new ReceiveStock($item, $location, 5));
    Inventory::stock()->count(new CountStock($item, $location, 5));

    $rows = DB::table('cmz_outbox_messages')->orderBy('occurred_at')->get();
    $row = $rows->first();

    if (! $row instanceof stdClass) {
        throw new RuntimeException('expected an outbox message');
    }

    $message = get_object_vars($row);
    $payloadJson = $message['payload'] ?? null;
    $type = $message['type'] ?? null;
    $aggregate = $message['aggregate_ref'] ?? null;
    $levelId = DB::table('cmz_stock_levels')->value('id');

    if (! is_string($payloadJson) || ! is_string($type) || ! is_string($aggregate) || ! is_string($levelId)) {
        throw new RuntimeException('expected outbox text and a stock level id');
    }

    $payload = json_decode($payloadJson, true);

    if (! is_array($payload)) {
        throw new RuntimeException('expected an outbox payload object');
    }

    expect($rows)->toHaveCount(1)
        ->and($type)->toBe('inventory.level_changed')
        ->and($aggregate)->toBe('stock_level:'.$levelId)
        ->and($payload)->toMatchArray([
            'stock_item_id' => $item->value, 'sku' => 'tee', 'location_id' => $location->value, 'location_code' => 'main',
            'on_hand' => 5, 'reserved' => 0, 'safety_stock' => 0, 'available' => 5, 'version' => 1,
        ]);
});

it('reads an untouched level as zeros and fails on unknown entities', function (): void {
    [$item, $location] = lvlPair();
    $view = Inventory::stock()->level($item, $location);

    expect([$view->onHand, $view->reserved, $view->version, $view->updatedAt])->toBe([0, 0, 0, null])
        ->and(fn () => Inventory::stock()->level(StockItemId::generate(), $location))->toThrow(StockItemNotFound::class)
        ->and(fn () => Inventory::stock()->level($item, LocationId::generate()))->toThrow(LocationNotFound::class)
        ->and(fn () => Inventory::stock()->receive(new ReceiveStock(StockItemId::generate(), $location, 1)))->toThrow(StockItemNotFound::class);
});

it('pages levels and movements with filters', function (): void {
    [$a, $main] = lvlPair('a');
    $b = Inventory::items()->create(new CreateStockItem('b'))->id;
    $east = Inventory::locations()->create(new CreateLocation('east', 'East'))->id;

    foreach ([[$a, $main], [$a, $east], [$b, $main]] as [$item, $location]) {
        Inventory::stock()->receive(new ReceiveStock($item, $location, 1, reference: 'po:'.$location->value));
    }

    $first = Inventory::stock()->list(new ListStockLevels(item: $a, first: 1));
    $second = Inventory::stock()->list(new ListStockLevels(item: $a, first: 1, after: $first->next));

    expect($first->items[0]->locationCode)->toBe('main')
        ->and($second->items[0]->locationCode)->toBe('east')
        ->and($second->hasMore())->toBeFalse()
        ->and(Inventory::stock()->list(new ListStockLevels(location: $main))->items)->toHaveCount(2);

    $moves = Inventory::movements()->list(new ListMovements(first: 2));
    $rest = Inventory::movements()->list(new ListMovements(first: 2, after: $moves->next));

    expect($moves->items)->toHaveCount(2)
        ->and($rest->items)->toHaveCount(1)
        ->and($rest->items[0]->id)->toBeGreaterThan($moves->items[1]->id)
        ->and(Inventory::movements()->list(new ListMovements(reference: 'po:'.$east->value))->items)->toHaveCount(1)
        ->and(fn () => Inventory::movements()->list(new ListMovements(after: Cursor::after('x'))))->toThrow(InvalidCommand::class)
        ->and(fn () => Inventory::stock()->list(new ListStockLevels(after: Cursor::after('1'))))->toThrow(InvalidCommand::class);
});

it('validates stock commands', function (): void {
    [$item, $location] = lvlPair();

    expect(fn () => new ReceiveStock($item, $location, 0))->toThrow(InvalidCommand::class)
        ->and(fn () => new AdjustStock($item, $location, 0, 'x'))->toThrow(InvalidCommand::class)
        ->and(fn () => new AdjustStock($item, $location, 1, ''))->toThrow(InvalidCommand::class)
        ->and(fn () => new CountStock($item, $location, -1))->toThrow(InvalidCommand::class)
        ->and(fn () => new SetSafetyStock($item, $location, -1))->toThrow(InvalidCommand::class)
        ->and(fn () => new ListStockLevels(first: 501))->toThrow(InvalidCommand::class);
});
