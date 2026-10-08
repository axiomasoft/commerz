<?php

declare(strict_types=1);

use Commerz\Errors\InvalidCommand;
use Commerz\Facades\Commerz;
use Commerz\Money\Currency;
use Commerz\Pagination\Cursor;
use Commerz\Stores\Contracts\Channels;
use Commerz\Stores\Contracts\Stores;
use Commerz\Stores\Data\ArchiveChannel;
use Commerz\Stores\Data\ArchiveStore;
use Commerz\Stores\Data\ChannelId;
use Commerz\Stores\Data\ChannelView;
use Commerz\Stores\Data\CreateChannel;
use Commerz\Stores\Data\CreateStore;
use Commerz\Stores\Data\ListChannels;
use Commerz\Stores\Data\ListStores;
use Commerz\Stores\Data\StoreId;
use Commerz\Stores\Data\StoreView;
use Commerz\Stores\Data\UpdateChannel;
use Commerz\Stores\Data\UpdateStore;
use Commerz\Stores\Enums\ChannelStatus;
use Commerz\Stores\Enums\StoreStatus;
use Commerz\Stores\Events\ChannelCreated;
use Commerz\Stores\Events\ChannelUpdated;
use Commerz\Stores\Events\StoreCreated;
use Commerz\Stores\Exceptions\ChannelArchived;
use Commerz\Stores\Exceptions\ChannelNotFound;
use Commerz\Stores\Exceptions\CurrencyNotAllowed;
use Commerz\Stores\Exceptions\DuplicateCode;
use Commerz\Stores\Exceptions\StoreNotFound;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makeStore(string $code = 'main'): StoreView
{
    return Commerz::stores()->create(new CreateStore($code, 'Main', Currency::of('RUB'), 'MN-'));
}

function makeChannel(StoreId $store, string $code = 'web', array $currencies = ['RUB', 'USD']): ChannelView
{
    return Commerz::channels()->create(new CreateChannel(
        store: $store,
        code: $code,
        name: 'Site',
        currencies: array_map(Currency::of(...), $currencies),
        locales: ['ru', 'en'],
        pricesIncludeTax: true,
    ));
}

it('exposes the root API and binds the contracts', function (): void {
    expect(Commerz::stores())->toBe(app(Stores::class))
        ->and(Commerz::channels())->toBe(app(Channels::class))
        ->and(Commerz::plugins()->describe())->not->toBeEmpty();
});

it('creates, finds and updates a store', function (): void {
    $store = makeStore();

    expect($store->code)->toBe('main')
        ->and($store->defaultCurrency->code)->toBe('RUB')
        ->and($store->orderNumberPrefix)->toBe('MN-')
        ->and($store->status)->toBe(StoreStatus::Active)
        ->and(Commerz::stores()->find($store->id)->id->equals($store->id))->toBeTrue();

    $updated = Commerz::stores()->update(new UpdateStore($store->id, name: 'Renamed'));

    expect($updated->name)->toBe('Renamed')->and($updated->orderNumberPrefix)->toBe('MN-');
});

it('rejects a duplicate store code without poisoning the surrounding transaction', function (): void {
    makeStore();

    DB::transaction(function (): void {
        expect(fn () => makeStore())->toThrow(DuplicateCode::class);
        expect(DB::table('cmz_stores')->count())->toBe(1);
    });
});

it('throws StoreNotFound for unknown stores', function (): void {
    expect(fn () => Commerz::stores()->find(StoreId::generate()))->toThrow(StoreNotFound::class)
        ->and(fn () => makeChannel(StoreId::generate()))->toThrow(StoreNotFound::class);
});

it('creates a channel with defaults and validates the currency rule', function (): void {
    $store = makeStore();
    $channel = makeChannel($store->id);

    expect($channel->defaultCurrency->code)->toBe('RUB')
        ->and($channel->defaultLocale)->toBe('ru')
        ->and(array_map(static fn (Currency $c): string => $c->code, $channel->currencies))->toBe(['RUB', 'USD'])
        ->and($channel->pricesIncludeTax)->toBeTrue()
        ->and($channel->allows(Currency::of('USD')))->toBeTrue()
        ->and($channel->allows(Currency::of('EUR')))->toBeFalse();

    expect(fn () => new CreateChannel($store->id, 'x', 'X', [Currency::of('RUB')], defaultCurrency: Currency::of('USD')))
        ->toThrow(CurrencyNotAllowed::class)
        ->and(fn () => new CreateChannel($store->id, 'x', 'X', [Currency::of('RUB'), Currency::of('RUB')]))
        ->toThrow(InvalidCommand::class);
});

it('rejects a duplicate channel code', function (): void {
    $store = makeStore();
    makeChannel($store->id);

    expect(fn () => makeChannel($store->id))->toThrow(DuplicateCode::class);
});

it('updates a channel and keeps the default inside the currency list', function (): void {
    $channel = makeChannel(makeStore()->id);

    $updated = Commerz::channels()->update(new UpdateChannel($channel->id, name: 'Web', currencies: [Currency::of('USD')], defaultCurrency: Currency::of('USD')));

    expect($updated->name)->toBe('Web')->and($updated->defaultCurrency->code)->toBe('USD');
    expect(fn () => Commerz::channels()->update(new UpdateChannel($channel->id, currencies: [Currency::of('EUR')])))
        ->toThrow(CurrencyNotAllowed::class);
});

it('guards new contexts with assertActive', function (): void {
    $channel = makeChannel(makeStore()->id);

    expect(Commerz::channels()->assertActive($channel->id, Currency::of('RUB'))->id->equals($channel->id))->toBeTrue()
        ->and(fn () => Commerz::channels()->assertActive($channel->id, Currency::of('EUR')))->toThrow(CurrencyNotAllowed::class)
        ->and(fn () => Commerz::channels()->assertActive(ChannelId::generate()))->toThrow(ChannelNotFound::class);

    $archived = Commerz::channels()->archive(new ArchiveChannel($channel->id));

    expect($archived->status)->toBe(ChannelStatus::Archived)
        ->and(Commerz::channels()->archive(new ArchiveChannel($channel->id))->status)->toBe(ChannelStatus::Archived)
        ->and(fn () => Commerz::channels()->assertActive($channel->id))->toThrow(ChannelArchived::class)
        ->and(fn () => Commerz::channels()->update(new UpdateChannel($channel->id, name: 'Nope')))->toThrow(ChannelArchived::class);
});

it('archives a store with its channels and refuses new channels in it', function (): void {
    $store = makeStore();
    $web = makeChannel($store->id, 'web');
    makeChannel($store->id, 'pos');

    $archived = Commerz::stores()->archive(new ArchiveStore($store->id));

    expect($archived->status)->toBe(StoreStatus::Archived)
        ->and(Commerz::channels()->find($web->id)->status)->toBe(ChannelStatus::Archived)
        ->and(Commerz::channels()->list(new ListChannels($store->id))->items)->toBe([])
        ->and(Commerz::channels()->list(new ListChannels($store->id, includeArchived: true))->items)->toHaveCount(2)
        ->and(fn () => makeChannel($store->id, 'late'))->toThrow(StoreNotFound::class);
});

it('lists with a keyset cursor ordered by code', function (): void {
    foreach (['c', 'a', 'd', 'b'] as $code) {
        makeStore($code);
    }

    $first = Commerz::stores()->list(new ListStores(first: 3));

    expect(array_map(static fn (StoreView $s): string => $s->code, $first->items))->toBe(['a', 'b', 'c'])
        ->and($first->hasMore())->toBeTrue();

    $second = Commerz::stores()->list(new ListStores(first: 3, after: $first->next));

    expect(array_map(static fn (StoreView $s): string => $s->code, $second->items))->toBe(['d'])
        ->and($second->hasMore())->toBeFalse()
        ->and(fn () => Cursor::fromToken('***'))->toThrow(InvalidCommand::class);
});

it('dispatches events only after commit', function (): void {
    Event::fake([StoreCreated::class, ChannelCreated::class, ChannelUpdated::class]);

    DB::transaction(function (): void {
        $channel = makeChannel(makeStore()->id);
        Commerz::channels()->archive(new ArchiveChannel($channel->id));

        Event::assertNotDispatched(StoreCreated::class);
    });

    Event::assertDispatched(StoreCreated::class);
    Event::assertDispatched(ChannelCreated::class);
    Event::assertDispatched(ChannelUpdated::class);
});

it('enforces the channel invariants in the database', function (): void {
    $store = makeStore();
    $now = now()->toDateTimeString();

    expect(fn () => DB::table('cmz_channels')->insert([
        'id' => (string) Str::uuid(), 'store_id' => $store->id->value, 'code' => 'raw', 'name' => 'Raw',
        'currencies' => '["RUB"]', 'default_currency' => 'USD', 'locales' => '["en"]', 'default_locale' => 'en',
        'created_at' => $now, 'updated_at' => $now,
    ]))->toThrow(QueryException::class);
});
