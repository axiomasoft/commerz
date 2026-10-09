<?php

declare(strict_types=1);

// Golden examples G1–G10 and G14–G17 of knowledge/shipping-design#golden, through Shipping::options() and the
// kernel's DeliveryOptions port (G11–G13 and the quote side of G15 and G17 are in DeliveryStageTest).

use Commerz\Money\Money;
use Commerz\Ports\Delivery\Contracts\DeliveryOptions;
use Commerz\Ports\Delivery\Data\Address;
use Commerz\Ports\Delivery\Data\DeliveryOption;
use Commerz\Ports\Delivery\Data\DeliveryRequest;
use Commerz\Ports\Delivery\Data\Parcel;
use Commerz\Shipping\Facades\Shipping;
use Commerz\Shipping\Methods\Data\ArchiveMethod;
use Commerz\Shipping\Methods\Data\SetMethodRates;
use Commerz\Shipping\Options\Internal\DatabaseShippingOptions;
use Commerz\Shipping\Rates\Data\RateSpec;
use Commerz\Shipping\Zones\Contracts\Zones;
use Commerz\Shipping\Zones\Data\ArchiveZone;
use Commerz\Shipping\Zones\Data\ZoneMember;
use Commerz\Testkit\Commerce;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Helpers.php';

uses(RefreshDatabase::class, OpEnvironment::class);

beforeEach(function (): void {
    $this->golden = opGolden();
    $this->web = $this->golden['channel'];
});

it('prices courier by the region zone over the country zone (G1)', function (): void {
    expect(opList(opRequest($this->web, 'RU-MOW', 800, '3000.00')))->toBe(['courier 250.00 msk 1', 'pickup 0.00 ru -']);
});

it('makes delivery free from the threshold, inclusive (G2)', function (): void {
    $options = opOptions(opRequest($this->web, 'RU-MOW', 800, '5000.00'));

    expect($options['courier']->price->toDecimal())->toBe('0.00')
        ->and($options['courier']->meta['free_above'])->toBeTrue()
        ->and(opOptions(opRequest($this->web, 'RU-MOW', 800, '4999.99'))['courier']->meta['free_above'])->toBeFalse();
});

it('picks the weight step with an inclusive bound and refuses above the last one (G3)', function (): void {
    expect(opList(opRequest($this->web, 'RU-SPE', 1000)))->toContain('courier 300.00 ru 3')
        ->and(opList(opRequest($this->web, 'RU-SPE', 1001)))->toContain('courier 500.00 ru 3')
        ->and(opVerdict(opRequest($this->web, 'RU-SPE', 5001), 'courier')->verdict)->toBe('weight_out_of_range')
        ->and(opList(opRequest($this->web, 'RU-SPE', 5001)))->toBe(['pickup 0.00 ru -']);
});

it('prefers the postal prefix zone and normalizes the postal code (G4)', function (): void {
    expect(opList(opRequest($this->web, 'RU-MOW', postal: '101 000')))->toContain('courier 200.00 msk-center -')
        ->and(opList(opRequest($this->web, 'RU-MOW', postal: '101-000')))->toContain('courier 200.00 msk-center -')
        ->and(opList(opRequest($this->web, 'RU-MOW', postal: '102000')))->toContain('courier 250.00 msk 1')
        ->and(opList(opRequest($this->web, null, postal: '101000')))->toContain('courier 200.00 msk-center -');
});

it('ignores a postal code that does not normalize, and one with LIKE wildcards', function (): void {
    expect(opList(opRequest($this->web, 'RU-MOW', postal: '10%')))->toContain('courier 250.00 msk 1')
        ->and(opList(opRequest($this->web, 'RU-MOW', postal: '1_1')))->toContain('courier 250.00 msk 1')
        ->and(opList(opRequest($this->web, null, postal: '%')))->toContain('courier 300.00 ru 3');
});

it('breaks a tie of equally specific zones by zone code, not by row order (G4b)', function (): void {
    $b = mtZone('b-zone', [ZoneMember::region('RU-KDA')]);
    $a = mtZone('a-zone', [ZoneMember::region('RU-KDA')]);
    mtMethod('equal', ['channels' => [$this->web], 'rates' => [
        mtRate($b->id, ['rate' => mtFlat('222.00')]),
        mtRate($a->id, ['rate' => mtFlat('111.00')]),
    ]]);

    expect(opList(opRequest($this->web, 'RU-KDA')))->toContain('equal 111.00 a-zone -')
        ->and(opVerdict(opRequest($this->web, 'RU-KDA'), 'equal')->zoneCode)->toBe('a-zone');
});

it('offers nothing without a tariff in the context currency (G5)', function (): void {
    $usd = opRequest($this->web, value: '30.00', currency: 'USD');

    expect(opVerdict($usd, 'courier')->verdict)->toBe('no_rate_for_currency')
        ->and(opList($usd))->toBe([]);
});

it('does not offer a method outside the context channel (G6)', function (): void {
    expect(opVerdict(opRequest($this->web), 'express')->verdict)->toBe('not_in_channel')
        ->and(opList(opRequest($this->web)))->not->toContain('express 900.00 ru -');
});

it('does not offer a disabled method (G7)', function (): void {
    expect(opVerdict(opRequest($this->web), 'post')->verdict)->toBe('method_disabled');
});

it('stops matching an archived zone (G8)', function (): void {
    $courier = $this->golden['methods']['courier'];
    $spb = $this->golden['zones']['spb'];
    mtMethods()->setRates(new SetMethodRates($courier->id, [...opCourierRates($this->golden['zones']), mtRate($spb->id, ['rate' => mtFlat('180.00')])]));

    expect(opList(opRequest($this->web, 'RU-SPE')))->toContain('courier 180.00 spb -');

    app(Zones::class)->archive(new ArchiveZone($spb->id));

    expect(opList(opRequest($this->web, 'RU-SPE')))->toContain('courier 300.00 ru 3');
});

it('does not offer a tariff whose rate plugin is gone or newer than the plugin (G9)', function (string $column, int|string $value): void {
    DB::table(mtTable('shipping_rates'))
        ->where('shipping_method_id', $this->golden['methods']['pickup']->id->value)
        ->update([$column => $value]);

    $verdict = opVerdict(opRequest($this->web), 'pickup');

    expect($verdict->verdict)->toBe('unsupported_rate')
        ->and($verdict->message)->not->toBe('')
        ->and(opList(opRequest($this->web)))->toBe(['courier 250.00 msk 1']);
})->with([
    'plugin removed' => ['rate_key', 'gone.rate'],
    'version ahead' => ['rate_version', 99],
]);

it('turns a rate plugin that throws or answers in another currency into invalid_rate_result (G10)', function (string $key): void {
    mtMethod('flaky', ['channels' => [$this->web], 'rates' => [mtRate($this->golden['zones']['ru']->id, ['rate' => new RateSpec($key)])]]);

    $verdict = opVerdict(opRequest($this->web), 'flaky');

    expect($verdict->verdict)->toBe('invalid_rate_result')
        ->and($verdict->price)->toBeNull()
        ->and(opList(opRequest($this->web)))->toBe(['courier 250.00 msk 1', 'pickup 0.00 ru -']);
})->with(['test.throwing', 'test.foreign_currency']);

it('charges in the quote what the options show (G14)', function (string $region, int $grams, string $value): void {
    $options = opOptions(opRequest($this->web, $region, $grams, $value));

    Commerce::fake();
    $quote = opQuote($this->web, [$value], 'courier', $region, $grams);

    expect($quote->delivery?->price->toDecimal())->toBe($options['courier']->price->toDecimal())
        ->and($quote->delivery?->taxIncluded)->toBe($options['courier']->meta['tax_included']);
})->with([
    'region' => ['RU-MOW', 800, '3000.00'],
    'free above' => ['RU-MOW', 800, '6000.00'],
    'weight step' => ['RU-SPE', 1001, '1000.00'],
]);

it('marks a tariff that includes tax (G15)', function (): void {
    expect(opOptions(opRequest($this->web))['courier']->meta['tax_included'])->toBeFalse();

    mtMethods()->setRates(new SetMethodRates($this->golden['methods']['courier']->id, opCourierRates($this->golden['zones'], ['taxIncluded' => true])));

    expect(opOptions(opRequest($this->web))['courier']->meta['tax_included'])->toBeTrue();
});

it('sorts by position, then price, then code (G16)', function (): void {
    mtMethod('cheap', ['channels' => [$this->web], 'position' => 20, 'rates' => [mtRate($this->golden['zones']['ru']->id, ['rate' => mtFlat('0.00')])]]);
    mtMethod('dear', ['channels' => [$this->web], 'position' => 20, 'rates' => [mtRate($this->golden['zones']['ru']->id, ['rate' => mtFlat('10.00')])]]);

    expect(array_map(static fn (DeliveryOption $o): string => $o->methodCode, app(DeliveryOptions::class)->options(opRequest($this->web))))
        ->toBe(['courier', 'cheap', 'pickup', 'dear']);
});

it('names the method in the context locale (G17)', function (): void {
    expect(opOptions(opRequest($this->web, locale: 'en'))['courier']->name)->toBe('Courier')
        ->and(opOptions(opRequest($this->web, locale: 'ru'))['courier']->name)->toBe('Курьер');
});

it('answers the kernel port with the same options as the list', function (): void {
    $request = opRequest($this->web, 'RU-MOW', postal: '101000');

    expect(app(DeliveryOptions::class))->toBeInstanceOf(DatabaseShippingOptions::class)
        ->and(app(DeliveryOptions::class)->options($request))->toEqual(Shipping::options()->list($request));
});

it('refuses a parcel value in another currency than the context', function (): void {
    $request = new DeliveryRequest(
        opContext($this->web),
        new Address('RU', 'RU-MOW'),
        new Parcel(800, Money::of('10.00', 'USD')),
    );

    expect(mtFailure(fn () => Shipping::options()->list($request)))->toBe('shipping.currency_mismatch @ parcel.value')
        ->and(mtFailure(fn () => Shipping::options()->explain($request)))->toBe('shipping.currency_mismatch @ parcel.value');
});

it('reads the options in one query', function (): void {
    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    Shipping::options()->list(opRequest($this->web, postal: '101000'));

    expect($queries)->toBe(1);
});

it('ignores a method without tariffs and an archived method', function (): void {
    mtMethod('bare', ['channels' => [$this->web]]);
    $archived = mtMethod('gone', ['channels' => [$this->web], 'rates' => [mtRate($this->golden['zones']['ru']->id)]]);
    mtMethods()->archive(new ArchiveMethod($archived->id));

    expect(opVerdicts(opRequest($this->web)))->toHaveKey('bare', 'no_zone')
        ->not->toHaveKey('gone');
});
