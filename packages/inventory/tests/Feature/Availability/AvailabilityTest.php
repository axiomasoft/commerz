<?php

declare(strict_types=1);

use Commerz\Identity\SellableRef;
use Commerz\Inventory\Availability\Internal\DatabaseAvailability;
use Commerz\Inventory\Facades\Inventory;
use Commerz\Inventory\Items\Data\ArchiveStockItem;
use Commerz\Inventory\Items\Enums\StockPolicy;
use Commerz\Inventory\Stock\Data\SetSafetyStock;
use Commerz\Ports\Stock\Contracts\StockAvailability;
use Commerz\Ports\Stock\Data\AvailabilityLine;
use Commerz\Ports\Stock\Data\CheckAvailability;
use Commerz\Stores\Contracts\Channels;
use Commerz\Stores\Data\ArchiveChannel;
use Commerz\Stores\Data\ChannelId;
use Commerz\Stores\Exceptions\ChannelArchived;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

require_once dirname(__DIR__).'/Reservations/Helpers.php';

/**
 * @param  array<string, int>  $lines  sellable id => units
 * @return array<string, int|null> sellable id => available
 */
function avlCheck(ChannelId $channel, array $lines): array
{
    $query = [];

    foreach ($lines as $sellable => $units) {
        $query[] = new AvailabilityLine(SellableRef::of('variant', $sellable), $units);
    }

    $answer = Inventory::availability()->check(new CheckAvailability($channel, $query));
    $available = [];

    foreach ($answer->lines as $line) {
        $available[$line->sellable->id] = $line->available;
    }

    return $available;
}

it('implements the stock availability port', function (): void {
    expect(app(StockAvailability::class))->toBeInstanceOf(DatabaseAvailability::class)
        ->and(Inventory::availability())->toBe(app(StockAvailability::class));
});

it('sums free stock over the channel sources only', function (): void {
    $channel = rsvChannel();
    $main = rsvSource($channel, 'main', 10);
    $backup = rsvSource($channel, 'backup', 20);
    $other = rsvSource(rsvChannel('pos'), 'other');
    $tee = rsvItem('tee', ['tee' => 1]);
    rsvReceive($tee, $main, 3);
    rsvReceive($tee, $backup, 5);
    rsvReceive($tee, $other, 100);
    rsvReserve($channel, 'order:1', ['tee' => 2]);

    expect(avlCheck($channel, ['tee' => 7]))->toBe(['tee' => 6]);
});

it('gives a kit the units its scarcest component allows', function (): void {
    $channel = rsvChannel();
    $main = rsvSource($channel, 'main');
    $body = rsvItem('body', ['kit' => 2]);
    $strap = rsvItem('strap', ['kit' => 1]);
    rsvReceive($body, $main, 5);
    rsvReceive($strap, $main, 3);

    expect(avlCheck($channel, ['kit' => 1]))->toBe(['kit' => 2]);
});

it('does not count expired holds the sweeper has not closed yet', function (): void {
    $channel = rsvChannel();
    $main = rsvSource($channel, 'main');
    $tee = rsvItem('tee', ['tee' => 1]);
    rsvReceive($tee, $main, 5);
    rsvReserve($channel, 'cart:1', ['tee' => 1], new DateTimeImmutable('+1 hour'));
    rsvReserve($channel, 'cart:2', ['tee' => 2], new DateTimeImmutable('-1 minute'));

    expect(rsvLevel($tee, $main)->reserved)->toBe(3)
        ->and(avlCheck($channel, ['tee' => 1]))->toBe(['tee' => 4]);
});

it('keeps safety stock out of availability', function (): void {
    $channel = rsvChannel();
    $main = rsvSource($channel, 'main');
    $tee = rsvItem('tee', ['tee' => 1]);
    rsvReceive($tee, $main, 5);
    Inventory::stock()->setSafetyStock(new SetSafetyStock($tee, $main, 2));

    expect(avlCheck($channel, ['tee' => 1]))->toBe(['tee' => 3]);
});

it('leaves archived locations and items out', function (): void {
    $channel = rsvChannel();
    $main = rsvSource($channel, 'main');
    $closed = rsvSource($channel, 'closed');
    $tee = rsvItem('tee', ['tee' => 1]);
    rsvItem('old', ['old' => 1]);
    rsvReceive($tee, $main, 2);
    rsvReceive($tee, $closed, 4);
    // Archiving refuses a location with stock; the read still must not count one archived some other way.
    DB::table('cmz_stock_locations')->where('id', $closed->value)->update(['status' => 'archived']);
    Inventory::items()->archive(new ArchiveStockItem(Inventory::items()->findBySku('old')->id));

    expect(avlCheck($channel, ['tee' => 1, 'old' => 1]))->toBe(['tee' => 2, 'old' => 0]);
});

it('answers null for untracked and unlimited unlinked sellables and 0 for unavailable ones', function (): void {
    $channel = rsvChannel();
    rsvSource($channel, 'main');
    rsvItem('ebook', ['ebook' => 1], StockPolicy::Untracked);
    rsvItem('strap', ['strap' => 1], StockPolicy::Backorder);

    expect(avlCheck($channel, ['ebook' => 1, 'strap' => 1, 'loose' => 1]))->toBe(['ebook' => null, 'strap' => null, 'loose' => 0]);

    rsvConfigure(['unlinked_sellables' => 'unlimited']);

    expect(avlCheck($channel, ['loose' => 1]))->toBe(['loose' => null]);
});

it('refuses an inactive channel', function (): void {
    $channel = rsvChannel();
    app(Channels::class)->archive(new ArchiveChannel($channel));

    expect(fn () => avlCheck($channel, ['tee' => 1]))->toThrow(ChannelArchived::class);
});

it('gives a backorder sellable nothing on a channel without sources', function (): void {
    $channel = rsvChannel();
    rsvItem('tee', ['tee' => 1], StockPolicy::Backorder);

    expect(avlCheck($channel, ['tee' => 1]))->toBe(['tee' => 0]);
});
