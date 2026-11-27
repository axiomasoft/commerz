<?php

declare(strict_types=1);

use Commerz\Errors\IdempotencyConflict;
use Commerz\Errors\InvalidCommand;
use Commerz\Identity\IdempotencyKey;
use Commerz\Identity\SellableRef;
use Commerz\Inventory\Facades\Inventory;
use Commerz\Inventory\Items\Data\ArchiveStockItem;
use Commerz\Inventory\Items\Data\StockItemId;
use Commerz\Inventory\Items\Data\UpdateStockItem;
use Commerz\Inventory\Items\Enums\StockItemStatus;
use Commerz\Inventory\Items\Enums\StockPolicy;
use Commerz\Inventory\Items\Exceptions\StockItemArchived;
use Commerz\Inventory\Items\Exceptions\StockItemNotEmpty;
use Commerz\Inventory\Items\Exceptions\StockItemNotFound;
use Commerz\Inventory\Items\Exceptions\UntrackedStockItem;
use Commerz\Inventory\Locations\Data\ArchiveLocation;
use Commerz\Inventory\Locations\Data\CreateLocation;
use Commerz\Inventory\Locations\Data\LocationId;
use Commerz\Inventory\Locations\Enums\LocationStatus;
use Commerz\Inventory\Locations\Exceptions\LocationArchived;
use Commerz\Inventory\Locations\Exceptions\LocationNotEmpty;
use Commerz\Inventory\Locations\Exceptions\LocationNotFound;
use Commerz\Inventory\Movements\Data\ListMovements;
use Commerz\Inventory\Reservations\Exceptions\ReservationConflict;
use Commerz\Inventory\Sources\Data\AssignSource;
use Commerz\Inventory\Stock\Data\AdjustStock;
use Commerz\Inventory\Stock\Data\CountStock;
use Commerz\Inventory\Stock\Data\ReceiveStock;
use Commerz\Inventory\Stock\Data\SetSafetyStock;
use Commerz\Inventory\Stock\Enums\MovementKind;
use Commerz\Inventory\Transfers\Contracts\Transfers;
use Commerz\Inventory\Transfers\Data\CancelTransfer;
use Commerz\Inventory\Transfers\Data\CreateTransfer;
use Commerz\Inventory\Transfers\Data\DispatchTransfer;
use Commerz\Inventory\Transfers\Data\ListTransfers;
use Commerz\Inventory\Transfers\Data\ReceiveTransfer;
use Commerz\Inventory\Transfers\Data\TransferId;
use Commerz\Inventory\Transfers\Data\TransferLine;
use Commerz\Inventory\Transfers\Data\TransferLineView;
use Commerz\Inventory\Transfers\Data\TransferReceipt;
use Commerz\Inventory\Transfers\Data\TransferView;
use Commerz\Inventory\Transfers\Enums\TransferState;
use Commerz\Inventory\Transfers\Events\TransferUpdated;
use Commerz\Inventory\Transfers\Exceptions\InvalidTransferState;
use Commerz\Inventory\Transfers\Exceptions\TransferNotFound;
use Commerz\Pagination\Cursor;
use Commerz\Ports\Stock\Contracts\StockReservations;
use Commerz\Ports\Stock\Data\AvailabilityLine;
use Commerz\Ports\Stock\Data\CheckAvailability;
use Commerz\Ports\Stock\Data\ReleaseReservation;
use Commerz\Ports\Stock\Exceptions\InsufficientStock;
use Commerz\Ports\Stock\Exceptions\ReservationNotFound;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

require_once __DIR__.'/Helpers.php';

it('keeps an item active and tracked while its last stock is in transit', function (string $change): void {
    [$from, $to, $item] = trfWorld();
    $sent = Inventory::transfers()->dispatch(new DispatchTransfer(trfCreate($from, $to, [$item->value => 10])->id));

    expect([rsvLevel($item, $from)->onHand, rsvLevel($item, $from)->reserved])->toBe([0, 0]);

    if ($change === 'archive') {
        expect(fn () => Inventory::items()->archive(new ArchiveStockItem($item)))->toThrow(StockItemNotEmpty::class);
    } else {
        expect(fn () => Inventory::items()->update(new UpdateStockItem($item, policy: StockPolicy::Untracked)))->toThrow(UntrackedStockItem::class);
    }

    $current = Inventory::items()->find($item);
    expect([$current->status, $current->policy])->toBe([StockItemStatus::Active, StockPolicy::Tracked]);

    $received = trfReceive($sent, [$item->value => [10]]);

    expect($received->state)->toBe(TransferState::Completed)
        ->and(rsvLevel($item, $to)->onHand)->toBe(10);
})->with(['archive', 'untrack']);

it('allows item lifecycle changes once all its goods in transit are settled as lost', function (): void {
    [$from, $to, $item] = trfWorld();
    $sent = Inventory::transfers()->dispatch(new DispatchTransfer(trfCreate($from, $to, [$item->value => 10])->id));
    trfReceive($sent, [$item->value => [0, 10]]);

    expect(Inventory::items()->update(new UpdateStockItem($item, policy: StockPolicy::Untracked))->policy)->toBe(StockPolicy::Untracked)
        ->and(Inventory::items()->archive(new ArchiveStockItem($item))->status)->toBe(StockItemStatus::Archived);
});

it('drafts a transfer and reserves the units at the source', function (): void {
    [$from, $to, $item] = trfWorld();
    $channel = rsvChannel();
    Inventory::sources()->assign(new AssignSource($channel, $from, 10));

    $transfer = trfCreate($from, $to, [$item->value => 4], 'tr:1');

    expect($transfer->state)->toBe(TransferState::Draft)
        ->and($transfer->reference)->toBe('tr:1')
        ->and([$transfer->fromCode, $transfer->toCode])->toBe(['main', 'shop'])
        ->and($transfer->dispatchedAt)->toBeNull()
        ->and($transfer->lines)->toHaveCount(1)
        ->and([$transfer->lines[0]->sku, $transfer->lines[0]->quantity, $transfer->lines[0]->inTransit])->toBe(['tee', 4, 0])
        ->and(rsvLevel($item, $from)->reserved)->toBe(4)
        ->and(Inventory::transfers()->find($transfer->id))->toEqual($transfer)
        ->and(Inventory::availability()->check(new CheckAvailability($channel, [new AvailabilityLine(SellableRef::of('variant', 'tee'), 100)]))->lines[0]->available)->toBe(6)
        ->and(fn () => rsvReserve($channel, 'order:1', ['tee' => 7]))->toThrow(InsufficientStock::class)
        ->and(DB::table('cmz_stock_reservations')->where('demand', 'transfer:'.$transfer->id->value)->whereNull('channel_id')->count())->toBe(1);
});

it('refuses a short source without leaving a document', function (): void {
    [$from, $to, $item] = trfWorld(3);

    expect(fn () => trfCreate($from, $to, [$item->value => 4]))
        ->toThrow(fn (InsufficientStock $e) => expect([$e->sellable, $e->requested, $e->available])->toBe(['stock_item:'.$item->value, 4, 3]))
        ->and(DB::table('cmz_stock_transfers')->count())->toBe(0)
        ->and(DB::table('cmz_stock_transfer_lines')->count())->toBe(0)
        ->and(DB::table('cmz_stock_reservations')->count())->toBe(0)
        ->and(rsvLevel($item, $from)->reserved)->toBe(0);
});

it('counts only free stock: safety stock and other reservations are not movable', function (): void {
    [$from, $to, $item] = trfWorld();
    Inventory::stock()->setSafetyStock(new SetSafetyStock($item, $from, 2));
    trfCreate($from, $to, [$item->value => 5]);

    expect(fn () => trfCreate($from, $to, [$item->value => 4]))
        ->toThrow(fn (InsufficientStock $e) => expect($e->available)->toBe(3))
        ->and(fn () => trfCreate($from, $to, [$item->value => 3]))->not->toThrow(InsufficientStock::class)
        ->and(rsvLevel($item, $from)->reserved)->toBe(8);
});

it('validates a transfer command and its entities', function (): void {
    [$from, $to, $item] = trfWorld();
    $line = new TransferLine($item, 1);

    expect(fn () => new CreateTransfer($from, $from, [$line]))
        ->toThrow(fn (InvalidCommand $e) => expect($e->code())->toBe('inventory.invalid_transfer'))
        ->and(fn () => new CreateTransfer($from, $to, []))->toThrow(InvalidCommand::class)
        ->and(fn () => new CreateTransfer($from, $to, [$line, $line]))->toThrow(InvalidCommand::class)
        ->and(fn () => new TransferLine($item, 0))->toThrow(InvalidCommand::class)
        ->and(fn () => new TransferReceipt($item, -1))->toThrow(InvalidCommand::class)
        ->and(fn () => new ReceiveTransfer(TransferId::generate(), []))->toThrow(InvalidCommand::class)
        ->and(fn () => new ReceiveTransfer(TransferId::generate(), [new TransferReceipt($item, 0, 0)]))->toThrow(InvalidCommand::class)
        ->and(fn () => trfCreate($from, LocationId::generate(), [$item->value => 1]))->toThrow(LocationNotFound::class)
        ->and(fn () => trfCreate($from, $to, [StockItemId::generate()->value => 1]))->toThrow(StockItemNotFound::class)
        ->and(DB::table('cmz_stock_transfers')->count())->toBe(0);
});

it('does not move untracked or archived stock, nor to or from an archived location', function (): void {
    [$from, $to, $item] = trfWorld();
    $untracked = rsvItem('service', ['service' => 1], StockPolicy::Untracked);
    $spare = rsvItem('spare', ['spare' => 1]);
    Inventory::items()->archive(new ArchiveStockItem($spare));
    $dormant = Inventory::locations()->create(new CreateLocation('dormant', 'Dormant'))->id;
    Inventory::locations()->archive(new ArchiveLocation($dormant));

    expect(fn () => trfCreate($from, $to, [$untracked->value => 1]))->toThrow(UntrackedStockItem::class)
        ->and(fn () => trfCreate($from, $to, [$spare->value => 1]))->toThrow(StockItemArchived::class)
        ->and(fn () => trfCreate($from, $dormant, [$item->value => 1]))->toThrow(LocationArchived::class)
        ->and(fn () => trfCreate($dormant, $to, [$item->value => 1]))->toThrow(LocationArchived::class)
        ->and(DB::table('cmz_stock_transfers')->count())->toBe(0);
});

it('dispatches once: the units leave the source and the reservation settles', function (): void {
    [$from, $to, $item] = trfWorld();
    $draft = trfCreate($from, $to, [$item->value => 4]);

    $sent = Inventory::transfers()->dispatch(new DispatchTransfer($draft->id));
    $source = rsvLevel($item, $from);

    expect($sent->state)->toBe(TransferState::InTransit)
        ->and($sent->dispatchedAt)->not->toBeNull()
        ->and($sent->lines[0]->inTransit)->toBe(4)
        ->and([$source->onHand, $source->reserved])->toBe([6, 0])
        ->and(DB::table('cmz_stock_levels')->where('location_id', $to->value)->count())->toBe(0)
        ->and(DB::table('cmz_stock_reservations')->where('demand', 'transfer:'.$draft->id->value)->value('state'))->toBe('closed')
        ->and(fn () => Inventory::transfers()->dispatch(new DispatchTransfer($draft->id)))
        ->toThrow(fn (InvalidTransferState $e) => expect([$e->code(), $e->state, $e->transfer])->toBe(['inventory.invalid_transfer_state', TransferState::InTransit, $draft->id->value]))
        ->and(rsvLevel($item, $from)->onHand)->toBe(6)
        ->and(Inventory::movements()->list(new ListMovements(item: $item, reference: 'transfer:'.$draft->id->value))->items)->toHaveCount(1)
        ->and(Inventory::movements()->list(new ListMovements(item: $item, reference: 'transfer:'.$draft->id->value))->items[0]->kind)->toBe(MovementKind::TransferOut)
        ->and(Inventory::movements()->list(new ListMovements(item: $item, reference: 'transfer:'.$draft->id->value))->items[0]->delta)->toBe(-4);
});

it('fails to dispatch when a count left fewer units on hand than reserved', function (): void {
    [$from, $to, $item] = trfWorld();
    $draft = trfCreate($from, $to, [$item->value => 8]);
    Inventory::stock()->count(new CountStock($item, $from, 5));

    expect(fn () => Inventory::transfers()->dispatch(new DispatchTransfer($draft->id)))->toThrow(InsufficientStock::class)
        ->and(Inventory::transfers()->find($draft->id)->state)->toBe(TransferState::Draft)
        ->and(rsvLevel($item, $from)->onHand)->toBe(5)
        ->and(rsvLevel($item, $from)->reserved)->toBe(8);
});

it('receives in parts and completes when every unit is received or lost', function (): void {
    [$from, $to, $item] = trfWorld();
    $sent = Inventory::transfers()->dispatch(new DispatchTransfer(trfCreate($from, $to, [$item->value => 5])->id));

    $partial = trfReceive($sent, [$item->value => [2]]);
    $destination = rsvLevel($item, $to);

    expect($partial->state)->toBe(TransferState::InTransit)
        ->and([$partial->lines[0]->received, $partial->lines[0]->lost, $partial->lines[0]->inTransit])->toBe([2, 0, 3])
        ->and($partial->completedAt)->toBeNull()
        ->and($destination->onHand)->toBe(2);

    $done = trfReceive($sent, [$item->value => [2, 1]]);

    expect($done->state)->toBe(TransferState::Completed)
        ->and([$done->lines[0]->received, $done->lines[0]->lost, $done->lines[0]->inTransit])->toBe([4, 1, 0])
        ->and($done->completedAt)->not->toBeNull()
        ->and(rsvLevel($item, $to)->onHand)->toBe(4)
        ->and(rsvLevel($item, $from)->onHand)->toBe(5)
        ->and(Inventory::movements()->list(new ListMovements(item: $item, location: $to))->items)->toHaveCount(2)
        ->and(Inventory::movements()->list(new ListMovements(item: $item, location: $to))->items[0]->kind)->toBe(MovementKind::TransferIn)
        ->and(fn () => trfReceive($sent, [$item->value => [1]]))->toThrow(InvalidTransferState::class);
});

it('writes off a whole line without a movement', function (): void {
    [$from, $to, $item] = trfWorld();
    $sent = Inventory::transfers()->dispatch(new DispatchTransfer(trfCreate($from, $to, [$item->value => 3])->id));

    $done = trfReceive($sent, [$item->value => [0, 3]]);

    expect($done->state)->toBe(TransferState::Completed)
        ->and(DB::table('cmz_stock_levels')->where('location_id', $to->value)->count())->toBe(0)
        ->and(DB::table('cmz_stock_movements')->where('kind', 'transfer_in')->count())->toBe(0);
});

it('refuses a receipt beyond what is in transit or for a foreign item and writes nothing', function (): void {
    [$from, $to, $item] = trfWorld();
    $other = rsvItem('cap', ['cap' => 1]);
    $sent = Inventory::transfers()->dispatch(new DispatchTransfer(trfCreate($from, $to, [$item->value => 5])->id));
    trfReceive($sent, [$item->value => [3]]);

    expect(fn () => trfReceive($sent, [$item->value => [2, 1]]))->toThrow(InvalidCommand::class)
        ->and(fn () => trfReceive($sent, [$item->value => [3]]))->toThrow(InvalidCommand::class)
        ->and(fn () => trfReceive($sent, [$item->value => [0, 3]]))->toThrow(InvalidCommand::class)
        ->and(fn () => trfReceive($sent, [$item->value => [PHP_INT_MAX, PHP_INT_MAX]]))->toThrow(InvalidCommand::class)
        ->and(fn () => trfReceive($sent, [$other->value => [1]]))->toThrow(InvalidCommand::class)
        ->and(rsvLevel($item, $to)->onHand)->toBe(3)
        ->and(Inventory::transfers()->find($sent->id)->lines[0]->inTransit)->toBe(2);
});

it('applies a receipt once per idempotency key', function (): void {
    [$from, $to, $item] = trfWorld();
    $sent = Inventory::transfers()->dispatch(new DispatchTransfer(trfCreate($from, $to, [$item->value => 5])->id));
    $key = IdempotencyKey::of('grn:1');

    $first = trfReceive($sent, [$item->value => [2]], $key);
    $again = trfReceive($sent, [$item->value => [2]], $key);

    expect($again)->toEqual($first)
        ->and($again->lines[0]->received)->toBe(2)
        ->and(rsvLevel($item, $to)->onHand)->toBe(2)
        ->and(DB::table('cmz_stock_movements')->where('kind', 'transfer_in')->count())->toBe(1)
        ->and(fn () => trfReceive($sent, [$item->value => [3]], $key))->toThrow(IdempotencyConflict::class)
        ->and(rsvLevel($item, $to)->onHand)->toBe(2);

    trfReceive($sent, [$item->value => [3]], IdempotencyKey::of('grn:2'));

    expect(trfReceive($sent, [$item->value => [2]], $key)->state)->toBe(TransferState::InTransit)
        ->and(Inventory::transfers()->find($sent->id)->state)->toBe(TransferState::Completed);
});

it('creates a transfer once per idempotency key', function (): void {
    [$from, $to, $item] = trfWorld();
    $key = IdempotencyKey::of('plan:1');

    $first = trfCreate($from, $to, [$item->value => 2], null, $key);
    $again = trfCreate($from, $to, [$item->value => 2], null, $key);

    expect($again->id->equals($first->id))->toBeTrue()
        ->and(DB::table('cmz_stock_transfers')->count())->toBe(1)
        ->and(rsvLevel($item, $from)->reserved)->toBe(2)
        ->and(fn () => trfCreate($from, $to, [$item->value => 3], null, $key))->toThrow(IdempotencyConflict::class);
});

it('cancels a draft and gives the reservation back', function (): void {
    [$from, $to, $item] = trfWorld();
    $draft = trfCreate($from, $to, [$item->value => 4]);

    $cancelled = Inventory::transfers()->cancel(new CancelTransfer($draft->id));

    expect($cancelled->state)->toBe(TransferState::Cancelled)
        ->and(rsvLevel($item, $from)->reserved)->toBe(0)
        ->and(rsvLevel($item, $from)->onHand)->toBe(10)
        ->and(Inventory::transfers()->cancel(new CancelTransfer($draft->id))->state)->toBe(TransferState::Cancelled)
        ->and(fn () => Inventory::transfers()->dispatch(new DispatchTransfer($draft->id)))->toThrow(InvalidTransferState::class)
        ->and(fn () => trfReceive($draft, [$item->value => [1]]))->toThrow(InvalidTransferState::class)
        ->and(trfCreate($from, $to, [$item->value => 10])->state)->toBe(TransferState::Draft);
});

it('does not cancel a shipped or finished transfer', function (): void {
    [$from, $to, $item] = trfWorld();
    $sent = Inventory::transfers()->dispatch(new DispatchTransfer(trfCreate($from, $to, [$item->value => 2])->id));

    expect(fn () => Inventory::transfers()->cancel(new CancelTransfer($sent->id)))->toThrow(InvalidTransferState::class);

    trfReceive($sent, [$item->value => [2]]);

    expect(fn () => Inventory::transfers()->cancel(new CancelTransfer($sent->id)))->toThrow(InvalidTransferState::class)
        ->and(fn () => Inventory::transfers()->cancel(new CancelTransfer(TransferId::generate())))->toThrow(TransferNotFound::class)
        ->and(fn () => Inventory::transfers()->find(TransferId::generate()))->toThrow(TransferNotFound::class);
});

it('moves several items in one document', function (): void {
    [$from, $to, $tee] = trfWorld();
    $cap = rsvItem('cap', ['cap' => 1]);
    rsvReceive($cap, $from, 4);

    $sent = Inventory::transfers()->dispatch(new DispatchTransfer(trfCreate($from, $to, [$tee->value => 3, $cap->value => 4])->id));
    $partial = trfReceive($sent, [$cap->value => [4], $tee->value => [1, 1]]);

    expect(array_map(static fn (TransferLineView $line): string => $line->sku, $partial->lines))->toBe(['cap', 'tee'])
        ->and($partial->state)->toBe(TransferState::InTransit)
        ->and(rsvLevel($cap, $from)->onHand)->toBe(0)
        ->and(rsvLevel($cap, $to)->onHand)->toBe(4)
        ->and(trfReceive($sent, [$tee->value => [1]])->state)->toBe(TransferState::Completed);
});

it('refuses receiving an item archived outside the inventory API', function (): void {
    [$from, $to, $item] = trfWorld(2);
    $sent = Inventory::transfers()->dispatch(new DispatchTransfer(trfCreate($from, $to, [$item->value => 2])->id));
    // The API prevents this transition while stock is in transit; retain the receipt's defensive status check.
    DB::table('cmz_stock_items')->where('id', $item->value)->update(['status' => 'archived']);

    expect(fn () => trfReceive($sent, [$item->value => [2]]))->toThrow(StockItemArchived::class)
        ->and(trfReceive($sent, [$item->value => [0, 2]])->state)->toBe(TransferState::Completed);
});

it('keeps locations with an open transfer from being archived', function (): void {
    [$from, $to, $item] = trfWorld();
    $draft = trfCreate($from, $to, [$item->value => 2]);

    expect(fn () => Inventory::locations()->archive(new ArchiveLocation($to)))->toThrow(LocationNotEmpty::class)
        ->and(fn () => Inventory::locations()->archive(new ArchiveLocation($from)))->toThrow(LocationNotEmpty::class);

    $sent = Inventory::transfers()->dispatch(new DispatchTransfer($draft->id));

    // In transit the source holds nothing for the document and the destination has no level yet.
    expect(fn () => Inventory::locations()->archive(new ArchiveLocation($to)))->toThrow(LocationNotEmpty::class)
        ->and(fn () => Inventory::locations()->archive(new ArchiveLocation($from)))->toThrow(LocationNotEmpty::class);

    trfReceive($sent, [$item->value => [2]]);

    expect(fn () => Inventory::locations()->archive(new ArchiveLocation($from)))->toThrow(LocationNotEmpty::class);
    Inventory::stock()->adjust(new AdjustStock($item, $from, -8, 'empty'));

    expect(Inventory::locations()->archive(new ArchiveLocation($from))->status)->toBe(LocationStatus::Archived);
});

it('lists transfers by state and location in creation order, with a cursor', function (): void {
    [$from, $to, $item] = trfWorld();
    $other = Inventory::locations()->create(new CreateLocation('hub', 'Hub'))->id;
    $a = trfCreate($from, $to, [$item->value => 1]);
    $b = trfCreate($from, $other, [$item->value => 1]);
    Inventory::stock()->receive(new ReceiveStock($item, $to, 1));
    $c = trfCreate($to, $from, [$item->value => 1]);
    Inventory::transfers()->cancel(new CancelTransfer($b->id));

    $all = Inventory::transfers()->list(new ListTransfers(first: 2));
    $rest = Inventory::transfers()->list(new ListTransfers(first: 2, after: $all->next));

    expect(array_map(static fn (TransferView $t): string => $t->id->value, $all->items))->toBe([$a->id->value, $b->id->value])
        ->and($all->next)->toBeInstanceOf(Cursor::class)
        ->and(array_map(static fn (TransferView $t): string => $t->id->value, $rest->items))->toBe([$c->id->value])
        ->and($rest->next)->toBeNull()
        ->and($all->items[0]->lines[0]->sku)->toBe('tee')
        ->and(Inventory::transfers()->list(new ListTransfers(TransferState::Cancelled))->items)->toHaveCount(1)
        ->and(Inventory::transfers()->list(new ListTransfers(location: $other))->items)->toHaveCount(1)
        ->and(Inventory::transfers()->list(new ListTransfers(TransferState::Draft, $to))->items)->toHaveCount(2)
        ->and(fn () => Inventory::transfers()->list(new ListTransfers(after: Cursor::after('nope'))))->toThrow(InvalidCommand::class)
        ->and(fn () => new ListTransfers(first: 0))->toThrow(InvalidCommand::class);
});

it('raises TransferUpdated after every committed transition and none for a repeat', function (): void {
    [$from, $to, $item] = trfWorld();
    Event::fake([TransferUpdated::class]);

    $draft = trfCreate($from, $to, [$item->value => 2]);
    $sent = Inventory::transfers()->dispatch(new DispatchTransfer($draft->id));
    $key = IdempotencyKey::of('grn:1');
    trfReceive($sent, [$item->value => [1]], $key);
    trfReceive($sent, [$item->value => [1]], $key);
    trfReceive($sent, [$item->value => [1]]);
    $second = trfCreate($from, $to, [$item->value => 1]);
    Inventory::transfers()->cancel(new CancelTransfer($second->id));
    Inventory::transfers()->cancel(new CancelTransfer($second->id));
    try {
        trfCreate($from, $to, [$item->value => 99]);
    } catch (InsufficientStock) {
    }

    $states = Event::dispatched(TransferUpdated::class)->map(static function (mixed $call): TransferState {
        if (! is_array($call) || ! array_key_exists(0, $call) || ! $call[0] instanceof TransferUpdated) {
            throw new RuntimeException('expected a TransferUpdated dispatch');
        }

        return $call[0]->transfer->state;
    })->all();

    expect($states)->toBe([
        TransferState::Draft,
        TransferState::InTransit,
        TransferState::InTransit,
        TransferState::Completed,
        TransferState::Draft,
        TransferState::Cancelled,
    ]);
});

it('keeps a transfer demand out of the sales reservation API', function (): void {
    [$from, $to, $item] = trfWorld();
    $draft = trfCreate($from, $to, [$item->value => 2]);
    $demand = 'transfer:'.$draft->id->value;

    expect(fn () => Inventory::reservations()->find($demand))->toThrow(ReservationNotFound::class);

    app(StockReservations::class)->release(new ReleaseReservation($demand));

    expect(rsvLevel($item, $from)->reserved)->toBe(2)
        ->and(fn () => rsvReserve(rsvChannel(), $demand, ['tee' => 1]))
        ->toThrow(fn (ReservationConflict $e) => expect($e->channel)->toBe('none'))
        ->and(Inventory::transfers()->dispatch(new DispatchTransfer($draft->id))->state)->toBe(TransferState::InTransit);
});

it('releases expired sales reservations that block a transfer', function (): void {
    [$from, $to, $item] = trfWorld(4);
    $channel = rsvChannel();
    Inventory::sources()->assign(new AssignSource($channel, $from, 10));
    rsvReserve($channel, 'cart:old', ['tee' => 3], new DateTimeImmutable('+1 hour'));

    expect(fn () => trfCreate($from, $to, [$item->value => 2]))->toThrow(InsufficientStock::class);

    DB::table('cmz_stock_reservations')->where('demand', 'cart:old')->update(['expires_at' => DB::raw("now() - interval '1 minute'")]);

    expect(trfCreate($from, $to, [$item->value => 2])->state)->toBe(TransferState::Draft)
        ->and(rsvLevel($item, $from)->reserved)->toBe(2);
});

it('exposes the transfers accessor on the root and the facade', function (): void {
    expect(Inventory::transfers())->toBe(app(Commerz\Inventory\Inventory::class)->transfers())
        ->and(Inventory::transfers())->toBe(app(Transfers::class));
});
