<?php

declare(strict_types=1);

use Commerz\Errors\InvalidCommand;
use Commerz\Identity\SellableRef;
use Commerz\Inventory\Facades\Inventory;
use Commerz\Inventory\Items\Enums\StockPolicy;
use Commerz\Inventory\Locations\Data\ArchiveLocation;
use Commerz\Inventory\Movements\Data\ListMovements;
use Commerz\Inventory\Movements\Data\MovementView;
use Commerz\Inventory\Reservations\Contracts\Reservations;
use Commerz\Inventory\Reservations\Data\ExpireReservations;
use Commerz\Inventory\Reservations\Data\ReservationLineView;
use Commerz\Inventory\Reservations\Enums\ReleaseCause;
use Commerz\Inventory\Reservations\Enums\ReservationState;
use Commerz\Inventory\Reservations\Events\StockConsumed;
use Commerz\Inventory\Reservations\Events\StockReleased;
use Commerz\Inventory\Reservations\Events\StockReserved;
use Commerz\Inventory\Reservations\Exceptions\ReservationConflict;
use Commerz\Inventory\Reservations\Internal\DatabaseReservations;
use Commerz\Inventory\Sources\Data\AssignSource;
use Commerz\Inventory\Stock\Enums\MovementKind;
use Commerz\Ports\Stock\Contracts\StockReservations;
use Commerz\Ports\Stock\Data\ConsumeReservation;
use Commerz\Ports\Stock\Data\ReleaseReservation;
use Commerz\Ports\Stock\Data\ReservedLine;
use Commerz\Ports\Stock\Data\StockAllocation;
use Commerz\Ports\Stock\Exceptions\InsufficientStock;
use Commerz\Ports\Stock\Exceptions\ReservationNotFound;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

require_once __DIR__.'/Helpers.php';

it('implements the stock reservation port as one instance', function (): void {
    expect(app(StockReservations::class))->toBeInstanceOf(DatabaseReservations::class)
        ->and(app(StockReservations::class))->toBe(app(Reservations::class))
        ->and(Inventory::reservations())->toBe(app(Reservations::class));
});

it('reserves all lines or nothing', function (): void {
    $channel = rsvChannel();
    $main = rsvSource($channel, 'main');
    $tee = rsvItem('tee', ['tee' => 1]);
    rsvItem('cap', ['cap' => 1]);
    rsvReceive($tee, $main, 5);

    expect(fn () => rsvReserve($channel, 'order:1', ['tee' => 2, 'cap' => 1]))
        ->toThrow(fn (InsufficientStock $e) => expect([$e->sellable, $e->requested, $e->available])->toBe(['variant:cap', 1, 0]))
        ->and(rsvLevel($tee, $main)->reserved)->toBe(0)
        ->and(rsvLines())->toBe(0)
        ->and(DB::table('cmz_stock_reservations')->count())->toBe(0);
});

it('splits a line across sources in priority order', function (): void {
    $channel = rsvChannel();
    $backup = rsvSource($channel, 'backup', 20);
    $main = rsvSource($channel, 'main', 10);
    $tee = rsvItem('tee', ['tee' => 1]);
    rsvReceive($tee, $main, 3);
    rsvReceive($tee, $backup, 5);

    $result = rsvReserve($channel, 'order:1', ['tee' => 6]);

    expect(rsvHeld($result))->toBe(['tee' => [['main', 3], ['backup', 3]]])
        ->and($result->lines[0]->quantity)->toBe(6)
        ->and(rsvLevel($tee, $main)->reserved)->toBe(3)
        ->and(rsvLevel($tee, $backup)->reserved)->toBe(3);
});

it('keeps a reservation in one location with the single_location strategy', function (): void {
    rsvConfigure(['allocation_strategy' => 'single_location']);
    $channel = rsvChannel();
    $main = rsvSource($channel, 'main', 10);
    $backup = rsvSource($channel, 'backup', 20);
    $tee = rsvItem('tee', ['tee' => 1]);
    rsvReceive($tee, $main, 3);
    rsvReceive($tee, $backup, 5);

    expect(rsvHeld(rsvReserve($channel, 'order:1', ['tee' => 4])))->toBe(['tee' => [['backup', 4]]])
        ->and(fn () => rsvReserve($channel, 'order:2', ['tee' => 2]))->not->toThrow(InsufficientStock::class)
        ->and(fn () => rsvReserve($channel, 'order:3', ['tee' => 3]))
        ->toThrow(fn (InsufficientStock $e) => expect($e->available)->toBe(1));
});

it('holds every component of a kit and shares a component between sellables', function (): void {
    $channel = rsvChannel();
    $main = rsvSource($channel, 'main');
    $body = rsvItem('body', ['kit' => 2, 'body' => 1]);
    $strap = rsvItem('strap', ['kit' => 1]);
    rsvReceive($body, $main, 5);
    rsvReceive($strap, $main, 3);

    expect(rsvHeld(rsvReserve($channel, 'order:1', ['kit' => 2])))->toBe(['kit' => [['main', 2]]])
        ->and(rsvLevel($body, $main)->reserved)->toBe(4)
        ->and(rsvLevel($strap, $main)->reserved)->toBe(2)
        ->and(fn () => rsvReserve($channel, 'order:2', ['kit' => 1]))
        ->toThrow(fn (InsufficientStock $e) => expect([$e->sellable, $e->available])->toBe(['variant:kit', 0]))
        ->and(rsvHeld(rsvReserve($channel, 'order:3', ['body' => 1])))->toBe(['body' => [['main', 1]]])
        ->and(rsvLevel($body, $main)->reserved)->toBe(5);

    $view = Inventory::reservations()->find('order:1');

    expect(array_map(fn (ReservationLineView $l) => [$l->sku, $l->quantityPerUnit, $l->units, $l->outstanding], $view->lines))
        ->toBe([['body', 2, 2, 2], ['strap', 1, 2, 2]]);
});

it('reserves backorder items beyond free stock in the first active source', function (): void {
    $channel = rsvChannel();
    $closed = rsvSource($channel, 'closed', 1);
    Inventory::locations()->archive(new ArchiveLocation($closed));
    $main = rsvSource($channel, 'main', 10);
    $backup = rsvSource($channel, 'backup', 20);
    $tee = rsvItem('tee', ['tee' => 1], StockPolicy::Backorder);
    rsvReceive($tee, $backup, 1);

    expect(rsvHeld(rsvReserve($channel, 'order:1', ['tee' => 3])))->toBe(['tee' => [['main', 2], ['backup', 1]]])
        ->and(rsvLevel($tee, $main)->reserved)->toBe(2)
        ->and(rsvLevel($tee, $main)->onHand)->toBe(0)
        ->and(DB::table('cmz_stock_levels')->where('location_id', $closed->value)->exists())->toBeFalse();
});

it('holds nothing for untracked sellables', function (): void {
    $channel = rsvChannel();
    rsvSource($channel, 'main');
    rsvItem('ebook', ['ebook' => 1], StockPolicy::Untracked);

    $result = rsvReserve($channel, 'order:1', ['ebook' => 7]);

    expect($result->lines[0]->quantity)->toBe(7)
        ->and($result->lines[0]->allocations)->toBe([])
        ->and(rsvLines())->toBe(0)
        ->and(DB::table('cmz_stock_levels')->count())->toBe(0)
        ->and(Inventory::reservations()->find('order:1')->lines)->toBe([]);
});

it('treats unlinked sellables as unavailable or, when configured, unlimited', function (): void {
    $channel = rsvChannel();
    rsvSource($channel, 'main');

    expect(fn () => rsvReserve($channel, 'order:1', ['loose' => 2]))
        ->toThrow(fn (InsufficientStock $e) => expect([$e->sellable, $e->requested, $e->available])->toBe(['variant:loose', 2, 0]));

    rsvConfigure(['unlinked_sellables' => 'unlimited']);
    $result = rsvReserve($channel, 'order:2', ['loose' => 2]);

    expect($result->lines[0]->quantity)->toBe(2)
        ->and($result->lines[0]->allocations)->toBe([])
        ->and(rsvLines())->toBe(0);
});

it('returns what a demand holds when it is reserved again', function (): void {
    $channel = rsvChannel();
    $main = rsvSource($channel, 'main');
    $tee = rsvItem('tee', ['tee' => 1]);
    rsvItem('ebook', ['ebook' => 1], StockPolicy::Untracked);
    rsvReceive($tee, $main, 5);
    $first = rsvReserve($channel, 'order:1', ['tee' => 2, 'ebook' => 1]);
    $lines = rsvLines();

    Event::fake([StockReserved::class]);
    $again = rsvReserve($channel, 'order:1', ['tee' => 2, 'ebook' => 1]);
    $other = rsvReserve($channel, 'order:1', ['tee' => 4]);

    expect($again)->toEqual($first)
        ->and(rsvHeld($other))->toBe(['tee' => [['main', 2]]])
        ->and($other->lines[0]->quantity)->toBe(2)
        ->and(rsvLines())->toBe($lines)
        ->and(rsvLevel($tee, $main)->reserved)->toBe(2);
    Event::assertNotDispatched(StockReserved::class);
});

it('refuses a demand reserved for another channel', function (): void {
    $web = rsvChannel('web');
    $pos = rsvChannel('pos');
    $main = rsvSource($web, 'main');
    Inventory::sources()->assign(new AssignSource($pos, $main));
    $tee = rsvItem('tee', ['tee' => 1]);
    rsvReceive($tee, $main, 5);
    rsvReserve($web, 'order:1', ['tee' => 1]);

    expect(fn () => rsvReserve($pos, 'order:1', ['tee' => 1]))
        ->toThrow(fn (ReservationConflict $e) => expect($e->code())->toBe('inventory.reservation_conflict'));
});

it('reserves a fully released demand anew as its next generation', function (): void {
    $channel = rsvChannel();
    $main = rsvSource($channel, 'main');
    $tee = rsvItem('tee', ['tee' => 1]);
    rsvReceive($tee, $main, 5);
    rsvReserve($channel, 'order:1', ['tee' => 2]);
    Inventory::reservations()->release(new ReleaseReservation('order:1'));

    expect(Inventory::reservations()->find('order:1')->state)->toBe(ReservationState::Closed);

    $result = rsvReserve($channel, 'order:1', ['tee' => 3]);
    $view = Inventory::reservations()->find('order:1');

    expect(rsvHeld($result))->toBe(['tee' => [['main', 3]]])
        ->and($view->generation)->toBe(2)
        ->and($view->state)->toBe(ReservationState::Active)
        ->and(array_map(fn (ReservationLineView $l) => $l->units, $view->lines))->toBe([3])
        ->and(rsvLines())->toBe(2)
        ->and(rsvLevel($tee, $main)->reserved)->toBe(3);
});

it('releases from the latest allocation, a sellable, or the whole demand', function (): void {
    $channel = rsvChannel();
    $main = rsvSource($channel, 'main', 10);
    $backup = rsvSource($channel, 'backup', 20);
    $tee = rsvItem('tee', ['tee' => 1]);
    $cap = rsvItem('cap', ['cap' => 1]);
    rsvReceive($tee, $main, 3);
    rsvReceive($tee, $backup, 3);
    rsvReceive($cap, $main, 3);
    rsvReserve($channel, 'order:1', ['tee' => 6, 'cap' => 2]);

    Inventory::reservations()->release(new ReleaseReservation('order:1', SellableRef::of('variant', 'tee'), 4));

    expect(rsvLevel($tee, $backup)->reserved)->toBe(0)
        ->and(rsvLevel($tee, $main)->reserved)->toBe(2)
        ->and(rsvLevel($cap, $main)->reserved)->toBe(2);

    Inventory::reservations()->release(new ReleaseReservation('order:1', SellableRef::of('variant', 'cap')));

    expect(rsvLevel($cap, $main)->reserved)->toBe(0)
        ->and(Inventory::reservations()->find('order:1')->state)->toBe(ReservationState::Active);

    Inventory::reservations()->release(new ReleaseReservation('order:1'));
    Inventory::reservations()->release(new ReleaseReservation('order:1'));
    Inventory::reservations()->release(new ReleaseReservation('order:unknown'));

    $view = Inventory::reservations()->find('order:1');

    expect(rsvLevel($tee, $main)->reserved)->toBe(0)
        ->and($view->state)->toBe(ReservationState::Closed)
        ->and(array_sum(array_map(fn (ReservationLineView $l) => $l->released, $view->lines)))->toBe(8);
});

it('consumes held units earliest allocation first or from a location', function (): void {
    $channel = rsvChannel();
    $main = rsvSource($channel, 'main', 10);
    $backup = rsvSource($channel, 'backup', 20);
    $tee = rsvItem('tee', ['tee' => 1]);
    rsvReceive($tee, $main, 3);
    rsvReceive($tee, $backup, 3);
    rsvReserve($channel, 'order:1', ['tee' => 6]);
    $sku = SellableRef::of('variant', 'tee');

    Inventory::reservations()->consume(new ConsumeReservation('order:1', $sku, 2, 'fulfillment:1'));
    Inventory::reservations()->consume(new ConsumeReservation('order:1', $sku, 2, 'fulfillment:2', 'backup'));

    $moves = Inventory::movements()->list(new ListMovements(reference: 'fulfillment:1'))->items;

    expect([rsvLevel($tee, $main)->onHand, rsvLevel($tee, $main)->reserved])->toBe([1, 1])
        ->and([rsvLevel($tee, $backup)->onHand, rsvLevel($tee, $backup)->reserved])->toBe([1, 1])
        ->and(array_map(fn (MovementView $m) => [$m->kind, $m->delta], $moves))->toBe([[MovementKind::Sale, -2]])
        ->and(fn () => Inventory::reservations()->consume(new ConsumeReservation('order:1', $sku, 2, 'fulfillment:3', 'main')))
        ->toThrow(ReservationNotFound::class)
        ->and(fn () => Inventory::reservations()->consume(new ConsumeReservation('order:1', $sku, 3, 'fulfillment:3')))
        ->toThrow(ReservationNotFound::class)
        ->and(fn () => Inventory::reservations()->consume(new ConsumeReservation('order:none', $sku, 1, 'fulfillment:3')))
        ->toThrow(ReservationNotFound::class);

    Inventory::reservations()->consume(new ConsumeReservation('order:1', $sku, 2, 'fulfillment:4'));

    expect(Inventory::reservations()->find('order:1')->state)->toBe(ReservationState::Closed)
        ->and(rsvLevel($tee, $main)->onHand + rsvLevel($tee, $backup)->onHand)->toBe(0);
});

it('consumes every component of a kit unit', function (): void {
    $channel = rsvChannel();
    $main = rsvSource($channel, 'main');
    $body = rsvItem('body', ['kit' => 2]);
    $strap = rsvItem('strap', ['kit' => 1]);
    rsvReceive($body, $main, 4);
    rsvReceive($strap, $main, 2);
    rsvReserve($channel, 'order:1', ['kit' => 2]);

    Inventory::reservations()->consume(new ConsumeReservation('order:1', SellableRef::of('variant', 'kit'), 1, 'fulfillment:1'));

    expect([rsvLevel($body, $main)->onHand, rsvLevel($body, $main)->reserved])->toBe([2, 2])
        ->and([rsvLevel($strap, $main)->onHand, rsvLevel($strap, $main)->reserved])->toBe([1, 1]);
});

it('cannot consume a backorder unit that is not on hand', function (): void {
    $channel = rsvChannel();
    $main = rsvSource($channel, 'main');
    $tee = rsvItem('tee', ['tee' => 1], StockPolicy::Backorder);
    rsvReserve($channel, 'order:1', ['tee' => 2]);

    expect(fn () => Inventory::reservations()->consume(new ConsumeReservation('order:1', SellableRef::of('variant', 'tee'), 1, 'fulfillment:1')))
        ->toThrow(InsufficientStock::class)
        ->and(rsvLevel($tee, $main)->reserved)->toBe(2)
        ->and(Inventory::reservations()->find('order:1')->lines[0]->consumed)->toBe(0);
});

it('ships units on hand before backorder units still to come', function (): void {
    $channel = rsvChannel();
    $main = rsvSource($channel, 'main', 10);
    $backup = rsvSource($channel, 'backup', 20);
    $tee = rsvItem('tee', ['tee' => 1], StockPolicy::Backorder);
    rsvReceive($tee, $backup, 1);
    rsvReserve($channel, 'order:1', ['tee' => 3]);

    Inventory::reservations()->consume(new ConsumeReservation('order:1', SellableRef::of('variant', 'tee'), 1, 'fulfillment:1'));

    expect([rsvLevel($tee, $backup)->onHand, rsvLevel($tee, $backup)->reserved])->toBe([0, 0])
        ->and(rsvLevel($tee, $main)->reserved)->toBe(2)
        ->and(fn () => Inventory::reservations()->consume(new ConsumeReservation('order:1', SellableRef::of('variant', 'tee'), 1, 'fulfillment:2')))
        ->toThrow(InsufficientStock::class);
});

it('consumes untracked and unlimited sellables the demand reserved without holding stock', function (): void {
    rsvConfigure(['unlinked_sellables' => 'unlimited']);
    $channel = rsvChannel();
    rsvSource($channel, 'main');
    rsvItem('ebook', ['ebook' => 1], StockPolicy::Untracked);
    rsvReserve($channel, 'order:1', ['ebook' => 2, 'loose' => 1]);

    Inventory::reservations()->consume(new ConsumeReservation('order:1', SellableRef::of('variant', 'ebook'), 2, 'fulfillment:1'));
    Inventory::reservations()->consume(new ConsumeReservation('order:1', SellableRef::of('variant', 'loose'), 1, 'fulfillment:1'));

    expect(Inventory::reservations()->find('order:1')->state)->toBe(ReservationState::Closed)
        ->and(fn () => Inventory::reservations()->consume(new ConsumeReservation('order:none', SellableRef::of('variant', 'ebook'), 1, 'fulfillment:2')))
        ->toThrow(ReservationNotFound::class);
});

it('consumes untracked and unlimited sellables with an explicit location hint', function (): void {
    rsvConfigure(['unlinked_sellables' => 'unlimited']);
    $channel = rsvChannel();
    rsvSource($channel, 'main');
    rsvItem('ebook', ['ebook' => 1], StockPolicy::Untracked);
    rsvReserve($channel, 'order:1', ['ebook' => 2, 'loose' => 1]);

    Inventory::reservations()->consume(new ConsumeReservation('order:1', SellableRef::of('variant', 'ebook'), 2, 'fulfillment:1', 'main'));
    Inventory::reservations()->consume(new ConsumeReservation('order:1', SellableRef::of('variant', 'loose'), 1, 'fulfillment:1', 'main'));

    expect(Inventory::reservations()->find('order:1')->state)->toBe(ReservationState::Closed)
        ->and(DB::table('cmz_stock_movements')->count())->toBe(0)
        ->and(DB::table('cmz_stock_levels')->count())->toBe(0);
});

it('repeats a live demand that holds only untracked sellables without writing', function (): void {
    $channel = rsvChannel();
    $main = rsvSource($channel, 'main');
    $tee = rsvItem('tee', ['tee' => 1]);
    rsvItem('ebook', ['ebook' => 1], StockPolicy::Untracked);
    rsvReceive($tee, $main, 5);
    rsvReserve($channel, 'order:1', ['ebook' => 1], new DateTimeImmutable('+1 hour'));

    Event::fake([StockReserved::class]);
    $again = rsvReserve($channel, 'order:1', ['ebook' => 1, 'tee' => 1]);

    expect(Inventory::reservations()->find('order:1')->generation)->toBe(1)
        ->and(array_map(fn (ReservedLine $l) => $l->quantity, $again->lines))->toBe([1, 0])
        ->and(rsvLevel($tee, $main)->reserved)->toBe(0);
    Event::assertNotDispatched(StockReserved::class);
});

it('expires reservations past their expiry and leaves the others', function (): void {
    $channel = rsvChannel();
    $main = rsvSource($channel, 'main');
    $tee = rsvItem('tee', ['tee' => 1]);
    rsvReceive($tee, $main, 5);
    rsvReserve($channel, 'cart:new', ['tee' => 1], new DateTimeImmutable('+1 hour'));
    rsvReserve($channel, 'order:1', ['tee' => 1]);
    // Reserved last, so no later reserve releases it on the way.
    rsvReserve($channel, 'cart:old', ['tee' => 2], new DateTimeImmutable('-1 minute'));

    Event::fake([StockReleased::class]);

    expect(Inventory::reservations()->expire(new ExpireReservations))->toBe(1)
        ->and(Inventory::reservations()->expire(new ExpireReservations(10)))->toBe(0)
        ->and(Inventory::reservations()->find('cart:old')->state)->toBe(ReservationState::Closed)
        ->and(Inventory::reservations()->find('cart:old')->lines[0]->expired)->toBe(2)
        ->and(Inventory::reservations()->find('cart:new')->state)->toBe(ReservationState::Active)
        ->and(rsvLevel($tee, $main)->reserved)->toBe(2)
        ->and(fn () => new ExpireReservations(0))->toThrow(InvalidCommand::class);
    Event::assertDispatched(StockReleased::class, fn (StockReleased $e): bool => $e->demand === 'cart:old' && $e->cause === ReleaseCause::Expired && $e->units === ['variant:tee' => 2]);
});

it('runs the expiry sweeper as an artisan command', function (): void {
    $channel = rsvChannel();
    $main = rsvSource($channel, 'main');
    $tee = rsvItem('tee', ['tee' => 1]);
    rsvReceive($tee, $main, 5);
    rsvReserve($channel, 'cart:1', ['tee' => 2], new DateTimeImmutable('-1 minute'));
    rsvReserve($channel, 'cart:2', ['tee' => 2], new DateTimeImmutable('-1 minute'));

    $this->artisan('commerz:inventory:expire-reservations', ['--limit' => '1'])->expectsOutputToContain('Closed 1')->assertSuccessful();
    $this->artisan('commerz:inventory:expire-reservations')->expectsOutputToContain('Closed 1')->assertSuccessful();
    $this->artisan('commerz:inventory:expire-reservations', ['--limit' => 'many'])->assertFailed();
    $this->artisan('commerz:inventory:expire-reservations', ['--limit' => '0'])->assertFailed();

    expect(rsvLevel($tee, $main)->reserved)->toBe(0);
});

it('releases expired holds on the levels a new reservation needs', function (): void {
    $channel = rsvChannel();
    $main = rsvSource($channel, 'main');
    $tee = rsvItem('tee', ['tee' => 1]);
    rsvReceive($tee, $main, 1);
    rsvReserve($channel, 'cart:1', ['tee' => 1], new DateTimeImmutable('-1 minute'));

    Event::fake([StockReleased::class]);

    expect(rsvHeld(rsvReserve($channel, 'order:1', ['tee' => 1])))->toBe(['tee' => [['main', 1]]])
        ->and(rsvLevel($tee, $main)->reserved)->toBe(1)
        ->and(Inventory::reservations()->find('cart:1')->lines[0]->expired)->toBe(1)
        ->and(Inventory::reservations()->find('cart:1')->state)->toBe(ReservationState::Active)
        ->and(Inventory::reservations()->expire(new ExpireReservations))->toBe(1)
        ->and(Inventory::reservations()->find('cart:1')->state)->toBe(ReservationState::Closed);
    Event::assertDispatched(StockReleased::class, fn (StockReleased $e): bool => $e->demand === 'cart:1' && $e->units === ['variant:tee' => 1]);
});

it('reserves an expired demand anew instead of returning its expired hold', function (): void {
    $channel = rsvChannel();
    $main = rsvSource($channel, 'main');
    $tee = rsvItem('tee', ['tee' => 1]);
    rsvReceive($tee, $main, 3);
    rsvReserve($channel, 'cart:1', ['tee' => 2], new DateTimeImmutable('-1 minute'));

    $result = rsvReserve($channel, 'cart:1', ['tee' => 3], new DateTimeImmutable('+1 hour'));
    $view = Inventory::reservations()->find('cart:1');

    expect(rsvHeld($result))->toBe(['tee' => [['main', 3]]])
        ->and($view->generation)->toBe(2)
        ->and(rsvLevel($tee, $main)->reserved)->toBe(3);
});

it('finds a reservation with its lines', function (): void {
    $channel = rsvChannel();
    $main = rsvSource($channel, 'main');
    $tee = rsvItem('tee', ['tee' => 1]);
    rsvReceive($tee, $main, 5);
    rsvReserve($channel, 'order:1', ['tee' => 2], new DateTimeImmutable('2030-01-01T00:00:00+00:00'));

    $view = Inventory::reservations()->find('order:1');
    $line = $view->lines[0];

    expect([$view->demand, $view->channel->value, $view->state, $view->generation])->toBe(['order:1', $channel->value, ReservationState::Active, 1])
        ->and($view->expiresAt?->getTimestamp())->toBe(1893456000)
        ->and([(string) $line->sellable, $line->sku, $line->locationCode, $line->item->value, $line->location->value])
        ->toBe(['variant:tee', 'tee', 'main', $tee->value, $main->value])
        ->and([$line->units, $line->consumed, $line->released, $line->expired, $line->outstanding])->toBe([2, 0, 0, 0, 2])
        ->and(fn () => Inventory::reservations()->find('order:none'))->toThrow(ReservationNotFound::class);
});

it('dispatches reservation events after commit and records level changes in the outbox', function (): void {
    $channel = rsvChannel();
    $main = rsvSource($channel, 'main');
    $tee = rsvItem('tee', ['tee' => 1]);
    rsvReceive($tee, $main, 5);
    $outbox = DB::table('cmz_outbox_messages')->count();
    Event::fake([StockReserved::class, StockReleased::class, StockConsumed::class]);
    $sku = SellableRef::of('variant', 'tee');

    rsvReserve($channel, 'order:1', ['tee' => 3]);
    Inventory::reservations()->consume(new ConsumeReservation('order:1', $sku, 1, 'fulfillment:1'));
    Inventory::reservations()->release(new ReleaseReservation('order:1', $sku, 1));

    Event::assertDispatched(StockReserved::class, fn (StockReserved $e): bool => $e->reservation->demand === 'order:1' && $e->reservation->lines[0]->units === 3);
    Event::assertDispatched(StockConsumed::class, fn (StockConsumed $e): bool => $e->quantity === 1 && $e->reference === 'fulfillment:1' && $e->allocations == [new StockAllocation('main', 1)]);
    Event::assertDispatched(StockReleased::class, fn (StockReleased $e): bool => $e->cause === ReleaseCause::Released && $e->units === ['variant:tee' => 1]);
    expect(DB::table('cmz_outbox_messages')->count() - $outbox)->toBe(3)
        ->and(DB::table('cmz_outbox_messages')->latest('occurred_at')->value('type'))->toBe('inventory.level_changed');
});

it('refuses a reservation whose stock units overflow int64', function (): void {
    $channel = rsvChannel();
    rsvSource($channel, 'main');
    rsvItem('grain', ['grain' => intdiv(PHP_INT_MAX, 2)]);

    expect(fn () => rsvReserve($channel, 'order:1', ['grain' => 3]))
        ->toThrow(fn (InvalidCommand $e) => expect($e->code())->toBe('inventory.invalid_quantity'));
});
