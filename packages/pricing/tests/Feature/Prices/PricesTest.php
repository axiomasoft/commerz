<?php

declare(strict_types=1);

use Commerz\Errors\InvalidCommand;
use Commerz\Identity\SellableRef;
use Commerz\Money\Money;
use Commerz\Pagination\Cursor;
use Commerz\Pricing\Facades\Pricing;
use Commerz\Pricing\PriceLists\Data\ArchivePriceList;
use Commerz\Pricing\PriceLists\Data\PriceListId;
use Commerz\Pricing\PriceLists\Exceptions\PriceListArchived;
use Commerz\Pricing\PriceLists\Exceptions\PriceListNotFound;
use Commerz\Pricing\Prices\Data\ListPrices;
use Commerz\Pricing\Prices\Data\PriceId;
use Commerz\Pricing\Prices\Data\PriceView;
use Commerz\Pricing\Prices\Data\RemovePrice;
use Commerz\Pricing\Prices\Data\ReschedulePrice;
use Commerz\Pricing\Prices\Data\SetPrices;
use Commerz\Pricing\Prices\Events\PricesChanged;
use Commerz\Pricing\Prices\Exceptions\PriceNotFoundById;
use Commerz\Pricing\Prices\Exceptions\PriceWindowOverlap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Assert;

uses(RefreshDatabase::class);

require_once __DIR__.'/../PriceLists/Helpers.php';

it('sets a price and reads it back', function (): void {
    $list = plsList();

    $price = Pricing::prices()->set(plsPrice($list->id, 'tee', '19.90', 'vip', 3, plsAt('2026-01-01'), plsAt('2026-02-01'), '25.00'));

    expect($price->priceList->equals($list->id))->toBeTrue()
        ->and((string) $price->sellable)->toBe('variant:tee')
        ->and($price->amount)->toEqual(Money::of('19.90', 'RUB'))
        ->and($price->compareAt)->toEqual(Money::of('25.00', 'RUB'))
        ->and($price->customerGroup)->toBe('vip')
        ->and($price->minQuantity)->toBe(3)
        ->and($price->validFrom)->toEqual(plsAt('2026-01-01'))
        ->and($price->validUntil)->toEqual(plsAt('2026-02-01'))
        ->and(Pricing::prices()->list(new ListPrices($list->id))->items)->toEqual([$price]);
});

it('updates the amount when the same key and window is set again', function (): void {
    $list = plsList();
    $first = plsSet($list->id, amount: '10.00');
    $again = Pricing::prices()->set(plsPrice($list->id, amount: '12.00', compareAt: '15.00'));

    expect($again->id->equals($first->id))->toBeTrue()
        ->and($again->amount)->toEqual(Money::of('12.00', 'RUB'))
        ->and($again->compareAt)->toEqual(Money::of('15.00', 'RUB'))
        ->and(DB::table('cmz_prices')->count())->toBe(1);

    $cleared = plsSet($list->id, amount: '12.00');

    expect($cleared->compareAt)->toBeNull();
});

it('rejects an overlapping window with the id of the price in the way', function (): void {
    $list = plsList();
    $existing = plsSet($list->id, from: plsAt('2026-01-01'), until: plsAt('2026-02-01'));

    try {
        plsSet($list->id, amount: '9.00', from: plsAt('2026-01-15'), until: plsAt('2026-03-01'));
        Assert::fail('The overlap was accepted.');
    } catch (PriceWindowOverlap $e) {
        expect($e->conflictingPrice)->toBe($existing->id->value)
            ->and($e->sellable)->toBe('variant:tee')
            ->and($e->priceList)->toBe($list->id->value)
            ->and($e->code())->toBe('pricing.price_window_overlap');
    }

    expect(DB::table('cmz_prices')->count())->toBe(1);
});

it('accepts adjacent windows and prices that differ in group or quantity step', function (): void {
    $list = plsList();

    plsSet($list->id, from: plsAt('2026-01-01'), until: plsAt('2026-02-01'));
    plsSet($list->id, from: plsAt('2026-02-01'), until: plsAt('2026-03-01'));
    plsSet($list->id, group: 'vip', from: plsAt('2026-01-15'), until: plsAt('2026-02-15'));
    plsSet($list->id, minQuantity: 10, from: plsAt('2026-01-15'), until: plsAt('2026-02-15'));
    plsSet($list->id, 'cap', from: plsAt('2026-01-15'), until: plsAt('2026-02-15'));

    expect(DB::table('cmz_prices')->count())->toBe(5);
});

it('treats the no-group price as its own group', function (): void {
    $list = plsList();

    plsSet($list->id);
    plsSet($list->id, group: 'vip');

    expect(fn () => plsSet($list->id, amount: '5.00', group: 'vip', from: plsAt('2026-01-01')))->toThrow(PriceWindowOverlap::class);
});

it('rejects a currency other than the one of the list', function (): void {
    $list = plsList();

    try {
        Pricing::prices()->set(plsPrice($list->id, currency: 'USD'));
        Assert::fail('The currency mismatch was accepted.');
    } catch (InvalidCommand $e) {
        expect($e->code())->toBe('pricing.currency_mismatch');
    }
});

it('rejects a price in an archived or missing list', function (): void {
    $list = plsList();
    Pricing::priceLists()->archive(new ArchivePriceList($list->id));

    expect(fn () => plsSet($list->id))->toThrow(PriceListArchived::class)
        ->and(fn () => Pricing::prices()->setMany(new SetPrices($list->id, [plsPrice($list->id)])))->toThrow(PriceListArchived::class);

    $other = plsList('other');
    $price = plsSet($other->id);
    Pricing::priceLists()->archive(new ArchivePriceList($other->id));

    expect(fn () => Pricing::prices()->reschedule(new ReschedulePrice($price->id, plsAt('2026-01-01'))))->toThrow(PriceListArchived::class)
        ->and(fn () => plsSet(PriceListId::generate()))->toThrow(PriceListNotFound::class);
});

it('sets many prices atomically in the order of the command', function (): void {
    $list = plsList();

    $views = Pricing::prices()->setMany(new SetPrices($list->id, [
        plsPrice($list->id, 'zed', '3.00'),
        plsPrice($list->id, 'alpha', '1.00'),
        plsPrice($list->id, 'alpha', '2.00', 'vip'),
    ]));

    expect(array_map(static fn (PriceView $view): string => $view->sellable->id.':'.$view->amount->amountMinor, $views))->toBe(['zed:300', 'alpha:100', 'alpha:200']);

    // The third price clashes with the existing alpha/vip price, so nothing of the batch stays.
    expect(fn () => Pricing::prices()->setMany(new SetPrices($list->id, [
        plsPrice($list->id, 'new', '1.00'),
        plsPrice($list->id, 'alpha', '9.00', 'vip', from: plsAt('2026-01-01')),
    ])))->toThrow(PriceWindowOverlap::class)
        ->and(DB::table('cmz_prices')->count())->toBe(3);

    expect(fn () => Pricing::prices()->setMany(new SetPrices($list->id, [
        plsPrice($list->id, 'new', '1.00'),
        plsPrice($list->id, 'bad', '1.00', currency: 'USD'),
    ])))->toThrow(InvalidCommand::class)
        ->and(DB::table('cmz_prices')->where('sellable_ref', 'variant:new')->count())->toBe(0);
});

it('updates existing prices in a batch', function (): void {
    $list = plsList();
    $existing = plsSet($list->id, 'tee', '10.00');

    $views = Pricing::prices()->setMany(new SetPrices($list->id, [plsPrice($list->id, 'tee', '11.00'), plsPrice($list->id, 'cap', '5.00')]));

    expect($views[0]->id->equals($existing->id))->toBeTrue()
        ->and($views[0]->amount->amountMinor)->toBe(1100)
        ->and(DB::table('cmz_prices')->count())->toBe(2);
});

it('rejects the same key and window twice in one batch', function (): void {
    $list = plsList();

    try {
        Pricing::prices()->setMany(new SetPrices($list->id, [plsPrice($list->id, 'tee', '1.00'), plsPrice($list->id, 'tee', '2.00')]));
        Assert::fail('The duplicate was accepted.');
    } catch (InvalidCommand $e) {
        expect($e->code())->toBe('pricing.duplicate_price');
    }
});

it('reschedules a price and rejects a move into another window', function (): void {
    $list = plsList();
    $january = plsSet($list->id, from: plsAt('2026-01-01'), until: plsAt('2026-02-01'));
    $march = plsSet($list->id, from: plsAt('2026-03-01'), until: plsAt('2026-04-01'));

    $moved = Pricing::prices()->reschedule(new ReschedulePrice($march->id, plsAt('2026-02-01'), plsAt('2026-03-15')));

    expect($moved->validFrom)->toEqual(plsAt('2026-02-01'))->and($moved->validUntil)->toEqual(plsAt('2026-03-15'));

    try {
        Pricing::prices()->reschedule(new ReschedulePrice($march->id, plsAt('2026-01-20'), plsAt('2026-03-15')));
        Assert::fail('The overlap was accepted.');
    } catch (PriceWindowOverlap $e) {
        expect($e->conflictingPrice)->toBe($january->id->value);
    }

    // Moving a price into its own window is not an overlap.
    Pricing::prices()->reschedule(new ReschedulePrice($march->id, plsAt('2026-02-01'), plsAt('2026-03-20')));

    expect(fn () => Pricing::prices()->reschedule(new ReschedulePrice(PriceId::generate())))->toThrow(PriceNotFoundById::class);
});

it('removes a price and ignores a repeat', function (): void {
    $list = plsList();
    $price = plsSet($list->id);
    Event::fake([PricesChanged::class]);

    Pricing::prices()->remove(new RemovePrice($price->id));
    Pricing::prices()->remove(new RemovePrice($price->id));
    Pricing::prices()->remove(new RemovePrice(PriceId::generate()));

    expect(DB::table('cmz_prices')->count())->toBe(0);
    Event::assertDispatchedTimes(PricesChanged::class, 1);
});

it('lists prices by sellable with a cursor and filters by sellable', function (): void {
    $list = plsList();
    $other = plsList('other');
    plsSet($list->id, 'b', '2.00');
    plsSet($list->id, 'a', '1.00', 'vip');
    plsSet($list->id, 'a', '1.50');
    plsSet($list->id, 'c', '3.00');
    plsSet($other->id, 'a', '9.00');

    $seen = [];
    $cursor = null;

    do {
        $page = Pricing::prices()->list(new ListPrices($list->id, first: 2, after: $cursor));
        $seen = [...$seen, ...array_map(static fn (object $price): string => (string) $price->sellable, $page->items)];
        $cursor = $page->next;
    } while ($cursor !== null);

    expect($seen)->toBe(['variant:a', 'variant:a', 'variant:b', 'variant:c'])
        ->and(Pricing::prices()->list(new ListPrices($list->id, SellableRef::of('variant', 'a')))->items)->toHaveCount(2);
});

it('rejects a malformed cursor', function (): void {
    $list = plsList();

    expect(fn () => Pricing::prices()->list(new ListPrices($list->id, after: Cursor::after('nonsense'))))
        ->toThrow(InvalidCommand::class);
});

it('keeps the transaction of the host usable after an overlap', function (): void {
    $list = plsList();
    plsSet($list->id, from: plsAt('2026-01-01'), until: plsAt('2026-02-01'));

    DB::transaction(function () use ($list): void {
        expect(fn () => plsSet($list->id, from: plsAt('2026-01-10'), until: plsAt('2026-01-20')))->toThrow(PriceWindowOverlap::class);

        plsSet($list->id, 'cap');
        expect(DB::table('cmz_prices')->count())->toBe(2);
    });

    expect(DB::table('cmz_prices')->count())->toBe(2);
});

it('rolls a whole host transaction back with its prices and events', function (): void {
    $list = plsList();
    Event::fake([PricesChanged::class]);

    try {
        DB::transaction(function () use ($list): void {
            plsSet($list->id);

            throw new RuntimeException('host rolls back');
        });
    } catch (RuntimeException) {
    }

    expect(DB::table('cmz_prices')->count())->toBe(0);
    Event::assertNotDispatched(PricesChanged::class);
});

it('raises PricesChanged with the sellables after the commit', function (): void {
    $list = plsList();
    Event::fake([PricesChanged::class]);

    plsSet($list->id, 'tee');
    Pricing::prices()->setMany(new SetPrices($list->id, [plsPrice($list->id, 'b'), plsPrice($list->id, 'a'), plsPrice($list->id, 'a', group: 'vip')]));

    $events = Event::dispatched(PricesChanged::class)->map(static function (mixed $payload): array {
        if (! is_array($payload) || ! isset($payload[0]) || ! $payload[0] instanceof PricesChanged) {
            throw new RuntimeException('Unexpected PricesChanged payload.');
        }

        return $payload[0]->sellables;
    })->values()->all();

    expect($events)->toBe([['variant:tee'], ['variant:a', 'variant:b']]);
});
