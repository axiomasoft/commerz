<?php

declare(strict_types=1);

use Commerz\Errors\InvalidCommand;
use Commerz\Operations\Contracts\Transactions;
use Commerz\Shipping\Common\Exceptions\DuplicateCode;
use Commerz\Shipping\Common\Internal\ShippingConfig;
use Commerz\Shipping\Zones\Contracts\Zones;
use Commerz\Shipping\Zones\Data\ArchiveZone;
use Commerz\Shipping\Zones\Data\ListZones;
use Commerz\Shipping\Zones\Data\RestoreZone;
use Commerz\Shipping\Zones\Data\SetZoneMembers;
use Commerz\Shipping\Zones\Data\UpdateZone;
use Commerz\Shipping\Zones\Data\ZoneId;
use Commerz\Shipping\Zones\Data\ZoneMember;
use Commerz\Shipping\Zones\Enums\ZoneStatus;
use Commerz\Shipping\Zones\Events\ZoneChanged;
use Commerz\Shipping\Zones\Exceptions\ZoneArchived;
use Commerz\Shipping\Zones\Exceptions\ZoneNotFound;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

require_once __DIR__.'/Helpers.php';

function znZones(): Zones
{
    return app(Zones::class);
}

it('creates a zone with members of three kinds, normalizing the prefix', function (): void {
    $zone = znCreate('msk', [
        ZoneMember::postalPrefix('RU', '1-01 '),
        ZoneMember::region('RU-MOW'),
        ZoneMember::country('RU'),
    ], 'Москва');

    expect($zone->code)->toBe('msk')
        ->and($zone->name)->toBe('Москва')
        ->and($zone->status)->toBe(ZoneStatus::Active)
        ->and($zone->members)->toEqual([ZoneMember::country('RU'), ZoneMember::postalPrefix('RU', '101'), ZoneMember::region('RU-MOW')])
        ->and(DB::table(znTable('shipping_zone_members'))->where('postal_prefix', '101')->exists())->toBeTrue()
        ->and(znZones()->find($zone->id))->toEqual($zone);
});

it('sorts members by country, then region and prefix, missing ones first', function (): void {
    $zone = znCreate('mix', [
        ZoneMember::postalPrefix('RU', '2'),
        ZoneMember::region('RU-SPE'),
        ZoneMember::postalPrefix('RU', '1'),
        ZoneMember::country('RU'),
        ZoneMember::country('DE'),
    ]);

    expect($zone->members)->toEqual([
        ZoneMember::country('DE'),
        ZoneMember::country('RU'),
        ZoneMember::postalPrefix('RU', '1'),
        ZoneMember::postalPrefix('RU', '2'),
        ZoneMember::region('RU-SPE'),
    ]);
});

it('lets zones overlap', function (): void {
    $ru = znCreate('ru', [ZoneMember::country('RU')]);
    $msk = znCreate('msk', [ZoneMember::country('RU'), ZoneMember::region('RU-MOW')]);

    expect($ru->members[0])->toEqual($msk->members[0])
        ->and(DB::table(znTable('shipping_zone_members'))->where('country', 'RU')->whereNull('region')->count())->toBe(2);
});

it('limits the members of a zone', function (): void {
    config()->set('commerz-shipping.max_zone_members', 2);
    app()->forgetInstance(ShippingConfig::class);
    app()->forgetInstance(Zones::class);

    $zone = znCreate('two', [ZoneMember::country('RU'), ZoneMember::country('DE')]);

    expect(fn () => znCreate('three', [ZoneMember::country('RU'), ZoneMember::country('DE'), ZoneMember::country('FR')]))
        ->toThrow(InvalidCommand::class, 'at most 2')
        ->and(fn () => znZones()->setMembers(new SetZoneMembers($zone->id, [ZoneMember::country('RU'), ZoneMember::country('DE'), ZoneMember::country('FR')])))
        ->toThrow(InvalidCommand::class)
        ->and(DB::table(znTable('shipping_zones'))->where('code', 'three')->exists())->toBeFalse()
        ->and(znZones()->find($zone->id)->members)->toHaveCount(2);
});

it('refuses a repeated code and leaves the host transaction alive', function (): void {
    znCreate('ru');

    app(Transactions::class)->run(function (): void {
        expect(fn () => znCreate('ru'))->toThrow(DuplicateCode::class);

        // A poisoned transaction would fail every statement after the violation.
        znCreate('de', [ZoneMember::country('DE')]);
    });

    expect(DB::table(znTable('shipping_zones'))->orderBy('code')->pluck('code')->all())->toBe(['de', 'ru']);
});

it('replaces the member set', function (): void {
    $zone = znCreate('eu', [ZoneMember::country('DE'), ZoneMember::country('FR')]);

    $updated = znZones()->setMembers(new SetZoneMembers($zone->id, [ZoneMember::country('FR'), ZoneMember::postalPrefix('IT', '00')]));

    expect($updated->members)->toEqual([ZoneMember::country('FR'), ZoneMember::postalPrefix('IT', '00')])
        ->and(DB::table(znTable('shipping_zone_members'))->count())->toBe(2)
        ->and(znZones()->setMembers(new SetZoneMembers($zone->id, []))->members)->toBe([])
        ->and(fn () => znZones()->setMembers(new SetZoneMembers(new ZoneId('01900000-0000-7000-8000-000000000000'), [])))
        ->toThrow(ZoneNotFound::class);
});

it('renames a zone', function (): void {
    $zone = znCreate('ru');

    expect(znZones()->update(new UpdateZone($zone->id, 'Russia'))->name)->toBe('Russia')
        ->and(fn () => znZones()->update(new UpdateZone(new ZoneId('01900000-0000-7000-8000-000000000000'), 'X')))
        ->toThrow(ZoneNotFound::class);
});

it('archives and restores, and refuses changes to an archived zone', function (): void {
    $zone = znCreate('ru');
    $archived = znZones()->archive(new ArchiveZone($zone->id));

    expect($archived->status)->toBe(ZoneStatus::Archived)
        ->and(fn () => znZones()->update(new UpdateZone($zone->id, 'New')))->toThrow(ZoneArchived::class)
        ->and(fn () => znZones()->setMembers(new SetZoneMembers($zone->id, [])))->toThrow(ZoneArchived::class)
        ->and(znZones()->find($zone->id)->members)->toHaveCount(1);

    $restored = znZones()->restore(new RestoreZone($zone->id));

    expect($restored->status)->toBe(ZoneStatus::Active)
        ->and(znZones()->update(new UpdateZone($zone->id, 'New'))->name)->toBe('New');
});

it('treats a repeated archive or restore as a no-op', function (): void {
    $zone = znCreate('ru');
    Event::fake([ZoneChanged::class]);

    $first = znZones()->archive(new ArchiveZone($zone->id));
    $second = znZones()->archive(new ArchiveZone($zone->id));

    expect($second)->toEqual($first)
        ->and(Event::dispatched(ZoneChanged::class)->count())->toBe(1);

    znZones()->restore(new RestoreZone($zone->id));
    znZones()->restore(new RestoreZone($zone->id));

    expect(Event::dispatched(ZoneChanged::class)->count())->toBe(2);
});

it('lists by code with filters and a cursor', function (): void {
    znCreate('a', [ZoneMember::country('RU')]);
    znCreate('b', [ZoneMember::country('DE')]);
    znCreate('c', [ZoneMember::country('RU'), ZoneMember::region('RU-MOW')]);
    $d = znCreate('d', [ZoneMember::country('RU')]);
    znZones()->archive(new ArchiveZone($d->id));

    $codes = static fn ($page): array => array_map(static fn ($view): string => $view->code, $page->items);

    $first = znZones()->list(new ListZones(limit: 2));
    $second = znZones()->list(new ListZones(limit: 2, after: $first->next));

    expect($codes($first))->toBe(['a', 'b'])
        ->and($first->hasMore())->toBeTrue()
        ->and($codes($second))->toBe(['c', 'd'])
        ->and($second->hasMore())->toBeFalse()
        ->and($codes(znZones()->list(new ListZones(status: ZoneStatus::Active))))->toBe(['a', 'b', 'c'])
        ->and($codes(znZones()->list(new ListZones(status: ZoneStatus::Archived))))->toBe(['d'])
        ->and($codes(znZones()->list(new ListZones(country: 'RU', status: ZoneStatus::Active))))->toBe(['a', 'c'])
        ->and($codes(znZones()->list(new ListZones(country: 'FR'))))->toBe([])
        ->and($first->items[0]->members)->toEqual([ZoneMember::country('RU')]);
});

it('raises ZoneChanged after the commit and not on rollback', function (): void {
    Event::fake([ZoneChanged::class]);

    $zone = znCreate('ru');
    znZones()->update(new UpdateZone($zone->id, 'Russia'));
    znZones()->update(new UpdateZone($zone->id, 'Russia'));
    znZones()->setMembers(new SetZoneMembers($zone->id, [ZoneMember::country('RU')]));
    znZones()->setMembers(new SetZoneMembers($zone->id, [ZoneMember::country('DE')]));

    expect(Event::dispatched(ZoneChanged::class)->count())->toBe(3);

    Event::fake([ZoneChanged::class]);

    try {
        app(Transactions::class)->run(function (): void {
            znCreate('de', [ZoneMember::country('DE')]);

            expect(Event::dispatched(ZoneChanged::class)->count())->toBe(0);

            throw new RuntimeException('host failure');
        });
    } catch (RuntimeException) {
    }

    expect(Event::dispatched(ZoneChanged::class)->count())->toBe(0)
        ->and(DB::table(znTable('shipping_zones'))->where('code', 'de')->exists())->toBeFalse();
});
