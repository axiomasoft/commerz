<?php

declare(strict_types=1);

use Commerz\Errors\InvalidCommand;
use Commerz\Money\Rate;
use Commerz\Operations\Contracts\Transactions;
use Commerz\Ports\Delivery\Data\Address;
use Commerz\Pricing\Common\Exceptions\DuplicateCode;
use Commerz\Pricing\Facades\Pricing;
use Commerz\Pricing\Taxes\Data\DefineTaxClass;
use Commerz\Pricing\Taxes\Data\DefineTaxZone;
use Commerz\Pricing\Taxes\Data\ListTaxRates;
use Commerz\Pricing\Taxes\Data\RemoveTaxRate;
use Commerz\Pricing\Taxes\Data\RescheduleTaxRate;
use Commerz\Pricing\Taxes\Data\SetTaxRate;
use Commerz\Pricing\Taxes\Data\SetTaxZoneMembers;
use Commerz\Pricing\Taxes\Data\TaxClassView;
use Commerz\Pricing\Taxes\Data\TaxRateId;
use Commerz\Pricing\Taxes\Data\TaxRateView;
use Commerz\Pricing\Taxes\Data\TaxZoneMember;
use Commerz\Pricing\Taxes\Events\TaxRatesChanged;
use Commerz\Pricing\Taxes\Exceptions\TaxClassNotFound;
use Commerz\Pricing\Taxes\Exceptions\TaxRateNotFound;
use Commerz\Pricing\Taxes\Exceptions\TaxRateOverlap;
use Commerz\Pricing\Taxes\Exceptions\TaxZoneMemberTaken;
use Commerz\Pricing\Taxes\Exceptions\TaxZoneNotFound;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Assert;

uses(RefreshDatabase::class);

require_once __DIR__.'/Helpers.php';

it('defines classes and rejects a repeated code', function (): void {
    $class = txClass('reduced');

    expect($class->code)->toBe('reduced')
        ->and($class->name)->toBe('Reduced')
        ->and(fn () => Pricing::taxes()->defineClass(new DefineTaxClass('reduced', 'Again')))->toThrow(DuplicateCode::class)
        ->and(DB::table('cmz_tax_classes')->count())->toBe(1);

    txClass('standard');

    expect(array_map(static fn (TaxClassView $view): string => $view->code, Pricing::taxes()->listClasses()))->toBe(['reduced', 'standard']);
});

it('defines a zone with its members and rejects a repeated code', function (): void {
    $zone = txZone('eu', [new TaxZoneMember('FR'), new TaxZoneMember('DE')]);

    expect($zone->code)->toBe('eu')
        ->and($zone->members)->toEqual([new TaxZoneMember('DE'), new TaxZoneMember('FR')])
        ->and(fn () => Pricing::taxes()->defineZone(new DefineTaxZone('eu', 'Again')))->toThrow(DuplicateCode::class)
        ->and(Pricing::taxes()->listZones())->toEqual([$zone]);
});

it('refuses a repeated member in one command', function (): void {
    expect(fn () => new DefineTaxZone('eu', 'EU', [new TaxZoneMember('DE'), new TaxZoneMember('DE')]))
        ->toThrow(InvalidCommand::class)
        ->and(fn () => new TaxZoneMember('de'))->toThrow(InvalidCommand::class)
        ->and(fn () => new TaxZoneMember('RU', 'DE-BE'))->toThrow(InvalidCommand::class);
});

it('replaces the members of a zone', function (): void {
    txZone('eu', [new TaxZoneMember('DE'), new TaxZoneMember('FR')]);

    $zone = Pricing::taxes()->setZoneMembers(new SetTaxZoneMembers('eu', [new TaxZoneMember('FR'), new TaxZoneMember('IT')]));

    expect($zone->members)->toEqual([new TaxZoneMember('FR'), new TaxZoneMember('IT')])
        ->and(DB::table('cmz_tax_zone_members')->count())->toBe(2)
        ->and(Pricing::taxes()->setZoneMembers(new SetTaxZoneMembers('eu', []))->members)->toBe([])
        ->and(fn () => Pricing::taxes()->setZoneMembers(new SetTaxZoneMembers('nowhere', [])))->toThrow(TaxZoneNotFound::class);
});

it('puts a country or region in one zone only (TAX-01)', function (): void {
    txZone('eu', [new TaxZoneMember('DE'), new TaxZoneMember('FR')]);
    txZone('uk', [new TaxZoneMember('GB')]);

    $takes = static fn (string $zone, TaxZoneMember ...$members) => fn () => Pricing::taxes()->setZoneMembers(new SetTaxZoneMembers($zone, array_values($members)));

    expect($takes('uk', new TaxZoneMember('GB'), new TaxZoneMember('FR')))->toThrow(TaxZoneMemberTaken::class)
        ->and(fn () => Pricing::taxes()->defineZone(new DefineTaxZone('dup', 'Dup', [new TaxZoneMember('DE')])))->toThrow(TaxZoneMemberTaken::class);

    try {
        $takes('uk', new TaxZoneMember('FR'))();
    } catch (TaxZoneMemberTaken $e) {
        expect($e->country)->toBe('FR')
            ->and($e->region)->toBeNull()
            ->and($e->otherZone)->toBe('eu')
            ->and($e->code())->toBe('pricing.tax_zone_member_taken');
    }

    // The failed commands left everything as it was: the rejected zone was not created and UK kept its member.
    expect(DB::table('cmz_tax_zones')->where('code', 'dup')->exists())->toBeFalse()
        ->and(Pricing::taxes()->listZones()[1]->members)->toEqual([new TaxZoneMember('GB')]);

    // A country and one of its regions are different pairs and may sit in different zones.
    txZone('moscow', [new TaxZoneMember('DE', 'DE-BE')]);

    expect(Pricing::taxes()->zoneFor(new Address('DE', 'DE-BE'))?->code)->toBe('moscow');
});

it('sets a rate and updates it for the same key and window', function (): void {
    txClass();
    txZone();

    $rate = txRate(bp: 2000, from: txAt('2026-01-01'));
    $again = txRate(bp: 2200, from: txAt('2026-01-01'));

    expect($rate->taxClass)->toBe('standard')
        ->and($rate->zone)->toBe('ru')
        ->and($rate->code)->toBe('vat')
        ->and($again->id->equals($rate->id))->toBeTrue()
        ->and($again->rate)->toEqual(Rate::bp(2200))
        ->and($again->validFrom)->toEqual(txAt('2026-01-01'))
        ->and($again->validUntil)->toBeNull()
        ->and(DB::table('cmz_tax_rates')->count())->toBe(1);
});

it('refuses a rate of an unknown class or zone', function (): void {
    txClass();
    txZone();

    expect(fn () => txRate(class: 'missing'))->toThrow(TaxClassNotFound::class)
        ->and(fn () => txRate(zone: 'missing'))->toThrow(TaxZoneNotFound::class)
        ->and(fn () => new SetTaxRate('standard', 'ru', 10_001))->toThrow(InvalidCommand::class)
        ->and(fn () => new SetTaxRate('standard', 'ru', 2200, 'vat', txAt('2026-02-01'), txAt('2026-01-01')))->toThrow(InvalidCommand::class);
});

it('lets windows of one rate follow each other and refuses overlaps (TAX-01)', function (): void {
    txClass();
    txZone();

    $old = txRate(bp: 2000, until: txAt('2026-01-01'));
    $new = txRate(bp: 2200, from: txAt('2026-01-01'));

    expect(DB::table('cmz_tax_rates')->count())->toBe(2);

    try {
        txRate(bp: 2500, from: txAt('2025-06-01'), until: txAt('2026-06-01'));
        Assert::fail('The overlap was accepted.');
    } catch (TaxRateOverlap $e) {
        expect($e->taxClass)->toBe('standard')
            ->and($e->zone)->toBe('ru')
            ->and($e->taxCode)->toBe('vat')
            ->and($e->conflictingRate)->toBe(min($old->id->value, $new->id->value))
            ->and($e->code())->toBe('pricing.tax_rate_overlap');
    }

    // Another tax code, class or zone is another key.
    txClass('reduced');
    txZone('by', [new TaxZoneMember('BY')]);
    txRate(bp: 500, code: 'gst');
    txRate(class: 'reduced', bp: 1000);
    txRate(zone: 'by', bp: 2000);

    expect(DB::table('cmz_tax_rates')->count())->toBe(5);
});

it('reschedules a rate, answers an overlap and a missing rate', function (): void {
    txClass();
    txZone();

    $old = txRate(bp: 2000, until: txAt('2026-01-01'));
    $new = txRate(bp: 2200, from: txAt('2026-01-01'));

    $moved = Pricing::taxes()->rescheduleRate(new RescheduleTaxRate($new->id, txAt('2026-02-01')));

    expect($moved->validFrom)->toEqual(txAt('2026-02-01'))
        ->and($moved->rate)->toEqual(Rate::bp(2200))
        ->and(fn () => Pricing::taxes()->rescheduleRate(new RescheduleTaxRate($old->id, null, txAt('2026-03-01'))))->toThrow(TaxRateOverlap::class)
        ->and(fn () => Pricing::taxes()->rescheduleRate(new RescheduleTaxRate(TaxRateId::generate(), txAt('2026-02-01'))))->toThrow(TaxRateNotFound::class)
        ->and(DB::table('cmz_tax_rates')->where('id', $old->id->value)->value('valid_until'))->toStartWith('2026-01-01');
});

it('removes a rate and ignores a repeated removal', function (): void {
    txClass();
    txZone();

    $rate = txRate();

    Pricing::taxes()->removeRate(new RemoveTaxRate($rate->id));
    Pricing::taxes()->removeRate(new RemoveTaxRate($rate->id));

    expect(DB::table('cmz_tax_rates')->count())->toBe(0);
});

it('lists rates by zone, class and the moment', function (): void {
    txClass();
    txClass('reduced');
    txZone();
    txZone('by', [new TaxZoneMember('BY')]);

    txRate(bp: 2000, until: txAt('2026-01-01'));
    txRate(bp: 2200, from: txAt('2026-01-01'));
    txRate(bp: 500, code: 'gst');
    txRate(class: 'reduced', bp: 1000);
    txRate(zone: 'by', bp: 2000);

    $bps = static fn (ListTaxRates $query): array => array_map(
        static fn (TaxRateView $rate): string => "{$rate->taxClass}/{$rate->zone}/{$rate->code}/{$rate->rate->basisPoints}",
        Pricing::taxes()->listRates($query),
    );

    expect($bps(new ListTaxRates))->toBe([
        'reduced/ru/vat/1000',
        'standard/by/vat/2000',
        'standard/ru/gst/500',
        'standard/ru/vat/2000',
        'standard/ru/vat/2200',
    ])
        ->and($bps(new ListTaxRates(zone: 'by')))->toBe(['standard/by/vat/2000'])
        ->and($bps(new ListTaxRates(zone: 'ru', taxClass: 'standard', at: txAt('2025-12-31 23:59:59'))))->toBe(['standard/ru/gst/500', 'standard/ru/vat/2000'])
        ->and($bps(new ListTaxRates(zone: 'ru', taxClass: 'standard', at: txAt('2026-01-01 00:00:00'))))->toBe(['standard/ru/gst/500', 'standard/ru/vat/2200'])
        ->and(fn () => Pricing::taxes()->listRates(new ListTaxRates(zone: 'missing')))->toThrow(TaxZoneNotFound::class)
        ->and(fn () => Pricing::taxes()->listRates(new ListTaxRates(taxClass: 'missing')))->toThrow(TaxClassNotFound::class);
});

it('finds the zone of an address: the region over the country, none outside the zones', function (): void {
    txZone('ru', [new TaxZoneMember('RU')]);
    txZone('moscow', [new TaxZoneMember('RU', 'RU-MOW')]);

    $zoneFor = static fn (Address $address): ?string => Pricing::taxes()->zoneFor($address)?->code;

    expect($zoneFor(new Address('RU', 'RU-MOW')))->toBe('moscow')
        ->and($zoneFor(new Address('RU', 'RU-SPE')))->toBe('ru')
        ->and($zoneFor(new Address('RU')))->toBe('ru')
        ->and($zoneFor(new Address('DE', 'DE-BE')))->toBeNull();

    // A country covered only through one region has no zone for the rest of it.
    txZone('berlin', [new TaxZoneMember('DE', 'DE-BE')]);

    expect($zoneFor(new Address('DE', 'DE-BE')))->toBe('berlin')
        ->and($zoneFor(new Address('DE', 'DE-BY')))->toBeNull()
        ->and($zoneFor(new Address('DE')))->toBeNull();
});

it('raises TaxRatesChanged once per written rate, after the commit', function (): void {
    txClass();
    txZone();
    Event::fake([TaxRatesChanged::class]);

    $rate = txRate();
    txRate(bp: 2300);
    Pricing::taxes()->rescheduleRate(new RescheduleTaxRate($rate->id, null, txAt('2027-01-01')));
    Pricing::taxes()->removeRate(new RemoveTaxRate($rate->id));
    Pricing::taxes()->removeRate(new RemoveTaxRate($rate->id));

    $events = Event::dispatched(TaxRatesChanged::class)->map(static function (mixed $payload): string {
        if (! is_array($payload) || ! isset($payload[0]) || ! $payload[0] instanceof TaxRatesChanged) {
            throw new RuntimeException('Unexpected TaxRatesChanged payload.');
        }

        return $payload[0]->taxClass.'/'.$payload[0]->zone;
    })->values()->all();

    expect($events)->toBe(['standard/ru', 'standard/ru', 'standard/ru', 'standard/ru']);
});

it('joins the transaction of the host and rolls back with it', function (): void {
    txClass();
    txZone();

    try {
        app(Transactions::class)->run(function (): void {
            txRate(bp: 2000, until: txAt('2026-01-01'));
            txRate(bp: 2200, from: txAt('2026-01-01'));

            throw new RuntimeException('host failure');
        });
    } catch (RuntimeException) {
    }

    expect(DB::table('cmz_tax_rates')->count())->toBe(0);
});
