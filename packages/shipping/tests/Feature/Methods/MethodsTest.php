<?php

declare(strict_types=1);

use Commerz\Errors\InvalidCommand;
use Commerz\Measures\LocalizedText;
use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Pagination\Cursor;
use Commerz\Pagination\Page;
use Commerz\Shipping\Common\Exceptions\DuplicateCode;
use Commerz\Shipping\Common\Internal\ShippingConfig;
use Commerz\Shipping\Methods\Contracts\Methods;
use Commerz\Shipping\Methods\Data\ArchiveMethod;
use Commerz\Shipping\Methods\Data\AssignMethodChannels;
use Commerz\Shipping\Methods\Data\DisableMethod;
use Commerz\Shipping\Methods\Data\EnableMethod;
use Commerz\Shipping\Methods\Data\ListMethods;
use Commerz\Shipping\Methods\Data\MethodId;
use Commerz\Shipping\Methods\Data\SetMethodRates;
use Commerz\Shipping\Methods\Data\UnassignMethodChannels;
use Commerz\Shipping\Methods\Data\UpdateMethod;
use Commerz\Shipping\Methods\Data\ZoneRate;
use Commerz\Shipping\Methods\Enums\MethodStatus;
use Commerz\Shipping\Methods\Events\MethodChanged;
use Commerz\Shipping\Methods\Exceptions\MethodArchived;
use Commerz\Shipping\Methods\Exceptions\MethodNotFound;
use Commerz\Shipping\Methods\Internal\MethodRows;
use Commerz\Shipping\Rates\Data\RateSpec;
use Commerz\Shipping\Tests\Fixtures\FixturePlugin;
use Commerz\Shipping\Zones\Contracts\Zones;
use Commerz\Shipping\Zones\Data\ArchiveZone;
use Commerz\Shipping\Zones\Data\ZoneId;
use Commerz\Shipping\Zones\Data\ZoneMember;
use Commerz\Stores\Contracts\Channels;
use Commerz\Stores\Data\ArchiveChannel;
use Commerz\Stores\Data\ChannelId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

require_once __DIR__.'/Helpers.php';
require_once __DIR__.'/../Zones/Helpers.php';

it('creates a method with channels and tariffs', function (): void {
    $web = mtChannel('web');
    $pos = mtChannel('pos');
    $ru = mtZone('ru');
    $msk = mtZone('msk', [ZoneMember::region('RU-MOW')]);

    $method = mtMethod('courier', [
        'name' => new LocalizedText(['ru' => 'Курьер', 'en' => 'Courier']),
        'channels' => [$pos, $web, $web],
        'position' => 5,
        'rates' => [
            mtRate($ru->id, ['rate' => new RateSpec('weight_table', ['steps' => [
                ['up_to_grams' => 1000, 'amount' => Money::of('300.00', 'RUB')],
                ['up_to_grams' => 5000, 'amount' => Money::of('500.00', 'RUB')],
            ]]), 'etaDays' => 3]),
            mtRate($msk->id, ['freeAbove' => Money::of('5000.00', 'RUB'), 'etaDays' => 1, 'taxIncluded' => true]),
        ],
    ]);

    $channels = [$web->value, $pos->value];
    sort($channels);

    expect($method->code)->toBe('courier')
        ->and($method->name->texts)->toEqual(['ru' => 'Курьер', 'en' => 'Courier'])
        ->and($method->carrier)->toBe('manual')
        ->and($method->status)->toBe(MethodStatus::Active)
        ->and($method->position)->toBe(5)
        ->and(array_map(static fn ($channel): string => $channel->value, $method->channels))->toBe($channels)
        ->and($method->rates)->toHaveCount(2)
        ->and($method->rates[0]->zoneCode)->toBe('msk')
        ->and($method->rates[0]->rate->key)->toBe('flat')
        ->and($method->rates[0]->rate->params)->toBe(['amount' => ['amount_minor' => 30000, 'currency' => 'RUB']])
        ->and($method->rates[0]->freeAbove?->equals(Money::of('5000.00', 'RUB')))->toBeTrue()
        ->and($method->rates[0]->taxIncluded)->toBeTrue()
        ->and($method->rates[0]->etaDays)->toBe(1)
        ->and($method->rates[0]->unsupported)->toBeNull()
        ->and($method->rates[1]->zoneCode)->toBe('ru')
        ->and($method->rates[1]->rate->key)->toBe('weight_table')
        ->and($method->rates[1]->freeAbove)->toBeNull()
        ->and($method->rates[1]->etaDays)->toBe(3)
        ->and(mtMethods()->find($method->id))->toEqual($method)
        ->and(mtMethods()->findByCode('courier'))->toEqual($method);
});

it('takes the default of tax_included from the configuration', function (): void {
    $zone = mtZone();
    $default = mtMethod('plain', ['rates' => [mtRate($zone->id)]]);

    config()->set('commerz-shipping.tax_included', true);
    app()->forgetInstance(ShippingConfig::class);
    app()->forgetInstance(MethodRows::class);
    app()->forgetInstance(Methods::class);

    $included = mtMethod('taxed', ['rates' => [mtRate($zone->id)]]);
    $explicit = mtMethod('net', ['rates' => [mtRate($zone->id, ['taxIncluded' => false])]]);

    expect($default->rates[0]->taxIncluded)->toBeFalse()
        ->and($included->rates[0]->taxIncluded)->toBeTrue()
        ->and($explicit->rates[0]->taxIncluded)->toBeFalse();
});

it('refuses an unknown carrier', function (): void {
    expect(mtFailure(fn () => mtMethod('x', ['carrier' => 'nowhere'])))->toBe('shipping.unknown_carrier @ carrier')
        ->and(DB::table(mtTable('shipping_methods'))->count())->toBe(0);
});

it('refuses an unknown rate and a rate with wrong parameters, naming the field', function (): void {
    $zone = mtZone();

    expect(mtFailure(fn () => mtMethod('x', ['rates' => [mtRate($zone->id), mtRate($zone->id, ['currency' => Currency::of('USD'), 'rate' => new RateSpec('ghost')])]])))
        ->toBe('shipping.unknown_rate @ rates.1.rate.key')
        ->and(mtFailure(fn () => mtMethod('x', ['rates' => [mtRate($zone->id, ['rate' => new RateSpec('flat', ['amount' => Money::of('1.00', 'USD')])])]])))
        ->toBe('shipping.invalid_rate_config @ rates.0.rate.params.amount')
        ->and(DB::table(mtTable('shipping_methods'))->count())->toBe(0)
        ->and(DB::table(mtTable('shipping_rates'))->count())->toBe(0);
});

it('refuses two tariffs for one zone and currency, and too many tariffs', function (): void {
    $zone = mtZone();

    expect(mtFailure(fn () => mtCommand('x', ['rates' => [mtRate($zone->id), mtRate($zone->id, ['rate' => mtFlat('1.00')])]])))
        ->toBe('shipping.duplicate_rate @ rates')
        ->and(mtFailure(fn () => mtCommand('x', ['rates' => array_map(
            static fn (int $i): ZoneRate => mtRate(new ZoneId(sprintf('00000000-0000-7000-8000-%012d', $i))),
            range(1, 501),
        )])))
        ->toBe('shipping.too_many_rates @ rates');
});

it('keeps a tariff in each currency of one zone', function (): void {
    $zone = mtZone();
    $method = mtMethod('multi', ['rates' => [
        mtRate($zone->id),
        mtRate($zone->id, ['currency' => Currency::of('USD'), 'rate' => mtFlat('5.00', 'USD')]),
    ]]);

    expect(array_map(static fn ($rate): string => $rate->currency->code, $method->rates))->toBe(['RUB', 'USD']);
});

it('refuses a free_above in another currency or a negative one', function (): void {
    $zone = mtZone();

    expect(mtFailure(fn () => mtRate($zone->id, ['freeAbove' => Money::of('50.00', 'USD')])))->toBe('shipping.currency_mismatch @ freeAbove')
        ->and(mtFailure(fn () => mtRate($zone->id, ['freeAbove' => Money::of('-1.00', 'RUB')])))->toBe('shipping.invalid_free_above @ freeAbove')
        ->and(mtFailure(fn () => mtRate($zone->id, ['etaDays' => 366])))->toBe('shipping.invalid_eta @ etaDays');
});

it('refuses a tariff in a zone that is missing or archived, writing nothing', function (): void {
    $zone = mtZone();
    $archived = mtZone('old');
    app(Zones::class)->archive(new ArchiveZone($archived->id));
    $missing = new ZoneId('00000000-0000-7000-8000-000000000001');

    expect(mtFailure(fn () => mtMethod('x', ['rates' => [mtRate($zone->id), mtRate($archived->id)]])))->toBe('shipping.zone_archived')
        ->and(mtFailure(fn () => mtMethod('x', ['rates' => [mtRate($missing)]])))->toBe('shipping.zone_not_found')
        ->and(DB::table(mtTable('shipping_methods'))->count())->toBe(0)
        ->and(DB::table(mtTable('shipping_rates'))->count())->toBe(0);
});

it('refuses an archived channel and a missing one', function (): void {
    $web = mtChannel('web');
    app(Channels::class)->archive(new ArchiveChannel($web));

    expect(mtFailure(fn () => mtMethod('x', ['channels' => [$web]])))->toBe('stores.channel_archived')
        ->and(mtFailure(fn () => mtMethod('x', ['channels' => [new ChannelId('00000000-0000-7000-8000-000000000001')]])))->toBe('stores.channel_not_found')
        ->and(DB::table(mtTable('shipping_methods'))->count())->toBe(0);
});

it('refuses a code taken, leaving the host transaction usable', function (): void {
    mtMethod('courier');

    DB::transaction(function (): void {
        expect(fn () => mtMethod('courier'))->toThrow(DuplicateCode::class);
        mtMethod('pickup');
    });

    expect(DB::table(mtTable('shipping_methods'))->count())->toBe(2);
});

it('creates a disabled method but not an archived one', function (): void {
    expect(mtMethod('off', ['status' => MethodStatus::Disabled])->status)->toBe(MethodStatus::Disabled)
        ->and(mtFailure(fn () => mtCommand('x', ['status' => MethodStatus::Archived])))->toBe('shipping.invalid_status @ status');
});

it('replaces all the tariffs with setRates', function (): void {
    $ru = mtZone('ru');
    $de = mtZone('de', [ZoneMember::country('DE')]);
    $method = mtMethod('courier', ['rates' => [mtRate($ru->id)]]);

    $changed = mtMethods()->setRates(new SetMethodRates($method->id, [mtRate($de->id, ['rate' => mtFlat('9.00')])]));

    expect($changed->rates)->toHaveCount(1)
        ->and($changed->rates[0]->zoneCode)->toBe('de')
        ->and($changed->rates[0]->rate->params)->toBe(['amount' => ['amount_minor' => 900, 'currency' => 'RUB']])
        ->and(DB::table(mtTable('shipping_rates'))->count())->toBe(1)
        ->and(mtMethods()->setRates(new SetMethodRates($method->id, []))->rates)->toBe([]);
});

it('changes nothing when setRates gets the tariffs it already has', function (): void {
    $zone = mtZone();
    $method = mtMethod('courier', ['rates' => [mtRate($zone->id, ['freeAbove' => Money::of('100.00', 'RUB'), 'etaDays' => 2])]]);
    Event::fake([MethodChanged::class]);

    $same = mtMethods()->setRates(new SetMethodRates($method->id, [mtRate($zone->id, ['freeAbove' => Money::of('100.00', 'RUB'), 'etaDays' => 2])]));

    expect($same)->toEqual($method);
    Event::assertNotDispatched(MethodChanged::class);

    mtMethods()->setRates(new SetMethodRates($method->id, [mtRate($zone->id, ['freeAbove' => Money::of('100.00', 'RUB'), 'etaDays' => 3])]));
    Event::assertDispatchedTimes(MethodChanged::class, 1);
});

it('refuses setRates with a zone that is archived', function (): void {
    $zone = mtZone();
    $method = mtMethod('courier', ['rates' => [mtRate($zone->id)]]);
    $old = mtZone('old');
    app(Zones::class)->archive(new ArchiveZone($old->id));

    expect(mtFailure(fn () => mtMethods()->setRates(new SetMethodRates($method->id, [mtRate($old->id)]))))->toBe('shipping.zone_archived')
        ->and(mtMethods()->find($method->id)->rates)->toHaveCount(1);
});

it('updates the name, the carrier and the position', function (): void {
    $method = mtMethod('courier');

    $updated = mtMethods()->update(new UpdateMethod($method->id, name: LocalizedText::of('de', 'Kurier'), position: 2));

    expect($updated->name->texts)->toBe(['de' => 'Kurier'])
        ->and($updated->position)->toBe(2)
        ->and($updated->carrier)->toBe('manual')
        ->and(mtFailure(fn () => mtMethods()->update(new UpdateMethod($method->id, carrier: 'nowhere'))))->toBe('shipping.unknown_carrier @ carrier');
});

it('assigns and unassigns channels idempotently', function (): void {
    $web = mtChannel('web');
    $pos = mtChannel('pos');
    $method = mtMethod('courier', ['channels' => [$web]]);
    Event::fake([MethodChanged::class]);

    $both = mtMethods()->assignChannels(new AssignMethodChannels($method->id, [$web, $pos]));
    mtMethods()->assignChannels(new AssignMethodChannels($method->id, [$web]));
    Event::assertDispatchedTimes(MethodChanged::class, 1);

    $one = mtMethods()->unassignChannels(new UnassignMethodChannels($method->id, [$web]));
    mtMethods()->unassignChannels(new UnassignMethodChannels($method->id, [$web]));
    Event::assertDispatchedTimes(MethodChanged::class, 2);

    expect($both->channels)->toHaveCount(2)
        ->and(array_map(static fn ($channel): string => $channel->value, $one->channels))->toBe([$pos->value])
        ->and(mtMethods()->unassignChannels(new UnassignMethodChannels($method->id, [$pos]))->channels)->toBe([]);
});

it('refuses to assign an archived channel', function (): void {
    $web = mtChannel('web');
    $pos = mtChannel('pos');
    $method = mtMethod('courier');
    app(Channels::class)->archive(new ArchiveChannel($pos));

    expect(mtFailure(fn () => mtMethods()->assignChannels(new AssignMethodChannels($method->id, [$web, $pos]))))->toBe('stores.channel_archived')
        ->and(mtMethods()->find($method->id)->channels)->toBe([]);
});

it('walks the statuses and keeps archived final', function (): void {
    $method = mtMethod('courier');
    Event::fake([MethodChanged::class]);

    expect(mtMethods()->disable(new DisableMethod($method->id))->status)->toBe(MethodStatus::Disabled)
        ->and(mtMethods()->disable(new DisableMethod($method->id))->status)->toBe(MethodStatus::Disabled)
        ->and(mtMethods()->enable(new EnableMethod($method->id))->status)->toBe(MethodStatus::Active)
        ->and(mtMethods()->enable(new EnableMethod($method->id))->status)->toBe(MethodStatus::Active)
        ->and(mtMethods()->archive(new ArchiveMethod($method->id))->status)->toBe(MethodStatus::Archived)
        ->and(mtMethods()->archive(new ArchiveMethod($method->id))->status)->toBe(MethodStatus::Archived);

    Event::assertDispatchedTimes(MethodChanged::class, 3);

    foreach ([
        fn () => mtMethods()->enable(new EnableMethod($method->id)),
        fn () => mtMethods()->disable(new DisableMethod($method->id)),
        fn () => mtMethods()->update(new UpdateMethod($method->id, position: 1)),
        fn () => mtMethods()->setRates(new SetMethodRates($method->id, [])),
        fn () => mtMethods()->assignChannels(new AssignMethodChannels($method->id, [mtChannel()])),
        fn () => mtMethods()->unassignChannels(new UnassignMethodChannels($method->id, [mtChannel()])),
    ] as $call) {
        expect(mtFailure($call))->toBe('shipping.method_archived');
    }

    expect(fn () => mtMethods()->archive(new ArchiveMethod($method->id)))->not->toThrow(MethodArchived::class);
});

it('fails to find what does not exist', function (): void {
    expect(fn () => mtMethods()->find(new MethodId('00000000-0000-7000-8000-000000000001')))->toThrow(MethodNotFound::class)
        ->and(fn () => mtMethods()->findByCode('ghost'))->toThrow(MethodNotFound::class)
        ->and(mtFailure(fn () => mtMethods()->archive(new ArchiveMethod(new MethodId('00000000-0000-7000-8000-000000000001')))))->toBe('shipping.method_not_found');
});

it('lists by status, channel and zone with a keyset cursor', function (): void {
    $web = mtChannel('web');
    $ru = mtZone('ru');
    mtMethod('b', ['position' => 1, 'channels' => [$web], 'rates' => [mtRate($ru->id)]]);
    mtMethod('a', ['position' => 1]);
    mtMethod('c', ['position' => 0, 'status' => MethodStatus::Disabled]);
    mtMethod('d', ['position' => -3]);

    $codes = static fn (Page $page): array => array_map(static fn ($method): string => $method->code, $page->items);

    $first = mtMethods()->list(new ListMethods(limit: 2));
    $second = mtMethods()->list(new ListMethods(after: $first->next, limit: 2));

    expect($codes($first))->toBe(['d', 'c'])
        ->and($first->next)->toBeInstanceOf(Cursor::class)
        ->and($codes($second))->toBe(['a', 'b'])
        ->and($second->next)->toBeNull()
        ->and($codes(mtMethods()->list(new ListMethods(status: MethodStatus::Disabled))))->toBe(['c'])
        ->and($codes(mtMethods()->list(new ListMethods(channel: $web))))->toBe(['b'])
        ->and($codes(mtMethods()->list(new ListMethods(zone: $ru->id))))->toBe(['b'])
        ->and(fn () => mtMethods()->list(new ListMethods(after: Cursor::after('garbage'))))->toThrow(InvalidCommand::class);
});

it('shows a tariff whose rule is gone as unsupported, keeping its stored parameters', function (): void {
    mtUsePlugins([FixturePlugin::class]);

    $zone = mtZone();
    $method = mtMethod('courier', ['rates' => [mtRate($zone->id, ['rate' => new RateSpec('test.fixed', ['amount' => Money::of('7.00', 'RUB')])])]]);

    expect($method->rates[0]->unsupported)->toBeNull()
        ->and($method->rates[0]->rate->params)->toBe(['amount' => ['amount_minor' => 700, 'currency' => 'RUB']]);

    mtUsePlugins([]);
    $after = mtMethods()->find($method->id);

    expect($after->rates)->toHaveCount(1)
        ->and($after->rates[0]->rate->key)->toBe('test.fixed')
        ->and($after->rates[0]->rate->params)->toBe(['amount' => ['amount_minor' => 700, 'currency' => 'RUB']])
        ->and($after->rates[0]->unsupported)->toBe("no rate contributes 'test.fixed'");
});

it('dispatches MethodChanged only after the commit', function (): void {
    $dispatched = [];
    Event::listen(MethodChanged::class, static function (MethodChanged $event) use (&$dispatched): void {
        $dispatched[] = $event->method->value;
    });

    try {
        DB::transaction(function () use (&$dispatched): void {
            mtMethod('courier');
            expect($dispatched)->toBe([]);

            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
    }

    expect($dispatched)->toBe([]);

    $method = mtMethod('pickup');

    expect($dispatched)->toBe([$method->id->value]);
});
