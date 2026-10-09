<?php

declare(strict_types=1);

use Commerz\Money\Currency;
use Commerz\Pricing\Common\Exceptions\DuplicateCode;
use Commerz\Pricing\Facades\Pricing;
use Commerz\Pricing\PriceLists\Data\ArchivePriceList;
use Commerz\Pricing\PriceLists\Data\AssignPriceListChannels;
use Commerz\Pricing\PriceLists\Data\CreatePriceList;
use Commerz\Pricing\PriceLists\Data\ListPriceLists;
use Commerz\Pricing\PriceLists\Data\PriceListId;
use Commerz\Pricing\PriceLists\Data\RestorePriceList;
use Commerz\Pricing\PriceLists\Data\UnassignPriceListChannels;
use Commerz\Pricing\PriceLists\Data\UpdatePriceList;
use Commerz\Pricing\PriceLists\Enums\PriceListStatus;
use Commerz\Pricing\PriceLists\Enums\TaxInclusion;
use Commerz\Pricing\PriceLists\Events\PriceListChanged;
use Commerz\Pricing\PriceLists\Exceptions\PriceListArchived;
use Commerz\Pricing\PriceLists\Exceptions\PriceListNotFound;
use Commerz\Stores\Exceptions\CurrencyNotAllowed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

require_once __DIR__.'/Helpers.php';

it('creates a price list with channels, priority and the default tax inclusion', function (): void {
    $web = plsChannel('web');
    $pos = plsChannel('pos');

    $list = Pricing::priceLists()->create(new CreatePriceList('retail', 'Retail', Currency::of('RUB'), [$pos, $web, $web], priority: 5));

    $expected = [$web->value, $pos->value];
    sort($expected);

    expect($list->code)->toBe('retail')
        ->and($list->currency->code)->toBe('RUB')
        ->and($list->priority)->toBe(5)
        ->and($list->taxInclusion)->toBe(TaxInclusion::AsChannel)
        ->and($list->status)->toBe(PriceListStatus::Active)
        ->and(array_map(static fn ($channel): string => $channel->value, $list->channels))->toBe($expected)
        ->and(Pricing::priceLists()->find($list->id)->name)->toBe('Retail');
});

it('rejects a duplicate code and keeps the caller transaction usable', function (): void {
    plsList('retail');

    DB::transaction(function (): void {
        expect(fn () => plsList('retail'))->toThrow(DuplicateCode::class);
        expect(Pricing::priceLists()->list(new ListPriceLists)->items)->toHaveCount(1);
    });
});

it('refuses a channel that does not sell in the currency of the list', function (): void {
    $usd = plsChannel('usd', 'USD');

    expect(fn () => plsList('retail', [$usd]))->toThrow(CurrencyNotAllowed::class)
        ->and(DB::table('cmz_price_lists')->count())->toBe(0);

    $list = plsList('retail');

    expect(fn () => Pricing::priceLists()->assignChannels(new AssignPriceListChannels($list->id, [$usd])))
        ->toThrow(CurrencyNotAllowed::class);
});

it('assigns and unassigns channels and ignores repeats', function (): void {
    $web = plsChannel('web');
    $pos = plsChannel('pos');
    $list = plsList('retail', [$web]);
    Event::fake([PriceListChanged::class]);

    $both = Pricing::priceLists()->assignChannels(new AssignPriceListChannels($list->id, [$web, $pos]));
    Pricing::priceLists()->assignChannels(new AssignPriceListChannels($list->id, [$web, $pos]));
    $one = Pricing::priceLists()->unassignChannels(new UnassignPriceListChannels($list->id, [$web, $web]));
    Pricing::priceLists()->unassignChannels(new UnassignPriceListChannels($list->id, [$web]));

    expect($both->channels)->toHaveCount(2)
        ->and($one->channels)->toHaveCount(1)
        ->and($one->channels[0]->equals($pos))->toBeTrue();
    Event::assertDispatchedTimes(PriceListChanged::class, 2);
});

it('archives and restores idempotently and refuses changes while archived', function (): void {
    $web = plsChannel('web');
    $list = plsList('retail');
    Event::fake([PriceListChanged::class]);

    $archived = Pricing::priceLists()->archive(new ArchivePriceList($list->id));
    Pricing::priceLists()->archive(new ArchivePriceList($list->id));

    expect($archived->status)->toBe(PriceListStatus::Archived)
        ->and(fn () => Pricing::priceLists()->update(new UpdatePriceList($list->id, name: 'X')))->toThrow(PriceListArchived::class)
        ->and(fn () => Pricing::priceLists()->assignChannels(new AssignPriceListChannels($list->id, [$web])))->toThrow(PriceListArchived::class)
        ->and(fn () => Pricing::priceLists()->unassignChannels(new UnassignPriceListChannels($list->id, [$web])))->toThrow(PriceListArchived::class);

    $restored = Pricing::priceLists()->restore(new RestorePriceList($list->id));
    Pricing::priceLists()->restore(new RestorePriceList($list->id));

    expect($restored->status)->toBe(PriceListStatus::Active);
    Event::assertDispatchedTimes(PriceListChanged::class, 2);
});

it('updates name, priority and tax inclusion; null leaves a field as it is', function (): void {
    $list = plsList('retail', priority: 1);

    $included = Pricing::priceLists()->update(new UpdatePriceList($list->id, taxInclusion: TaxInclusion::Included));
    $renamed = Pricing::priceLists()->update(new UpdatePriceList($list->id, name: 'Shop', priority: 9));
    $excluded = Pricing::priceLists()->update(new UpdatePriceList($list->id, taxInclusion: TaxInclusion::Excluded));
    $channel = Pricing::priceLists()->update(new UpdatePriceList($list->id, taxInclusion: TaxInclusion::AsChannel));

    expect($included->taxInclusion)->toBe(TaxInclusion::Included)->and($included->priority)->toBe(1)
        ->and($renamed->name)->toBe('Shop')->and($renamed->priority)->toBe(9)->and($renamed->taxInclusion)->toBe(TaxInclusion::Included)
        ->and($excluded->taxInclusion)->toBe(TaxInclusion::Excluded)
        ->and($channel->taxInclusion)->toBe(TaxInclusion::AsChannel)
        ->and(DB::table('cmz_price_lists')->value('tax_included'))->toBeNull();
});

it('does not touch a list on an update that changes nothing', function (): void {
    $list = plsList('retail');

    $same = Pricing::priceLists()->update(new UpdatePriceList($list->id, name: 'Retail', taxInclusion: TaxInclusion::AsChannel));

    expect($same->updatedAt)->toEqual($list->updatedAt);
});

it('lists by code with a cursor and filters by status, channel and currency', function (): void {
    $web = plsChannel('web');
    plsList('c-list', [$web]);
    plsList('a-list');
    $usd = plsList('b-list', currency: 'USD');
    Pricing::priceLists()->archive(new ArchivePriceList($usd->id));

    $first = Pricing::priceLists()->list(new ListPriceLists(first: 2));
    $second = Pricing::priceLists()->list(new ListPriceLists(first: 2, after: $first->next));

    expect(array_map(static fn ($list): string => $list->code, $first->items))->toBe(['a-list', 'b-list'])
        ->and($first->hasMore())->toBeTrue()
        ->and(array_map(static fn ($list): string => $list->code, $second->items))->toBe(['c-list'])
        ->and($second->hasMore())->toBeFalse()
        ->and(Pricing::priceLists()->list(new ListPriceLists(status: PriceListStatus::Archived))->items)->toHaveCount(1)
        ->and(Pricing::priceLists()->list(new ListPriceLists(channel: $web))->items[0]->code)->toBe('c-list')
        ->and(Pricing::priceLists()->list(new ListPriceLists(currency: Currency::of('USD')))->items[0]->code)->toBe('b-list');
});

it('reports a missing price list', function (): void {
    $missing = PriceListId::generate();

    expect(fn () => Pricing::priceLists()->find($missing))->toThrow(PriceListNotFound::class)
        ->and(fn () => Pricing::priceLists()->archive(new ArchivePriceList($missing)))->toThrow(PriceListNotFound::class)
        ->and(fn () => Pricing::priceLists()->update(new UpdatePriceList($missing, name: 'X')))->toThrow(PriceListNotFound::class);
});

it('raises PriceListChanged after the commit and drops it on rollback', function (): void {
    Event::fake([PriceListChanged::class]);

    $list = plsList('retail');

    Event::assertDispatched(PriceListChanged::class, static fn (PriceListChanged $event): bool => $event->priceList->equals($list->id));

    try {
        DB::transaction(function (): void {
            plsList('other');

            throw new RuntimeException('host rolls back');
        });
    } catch (RuntimeException) {
    }

    Event::assertDispatchedTimes(PriceListChanged::class, 1);
});
