<?php

declare(strict_types=1);

// Every explain verdict of knowledge/shipping-design#api.

use Commerz\Shipping\Methods\Data\ArchiveMethod;
use Commerz\Shipping\Options\Internal\MethodPricer;
use Commerz\Shipping\Options\Internal\PricedMethod;
use Commerz\Shipping\Rates\Contracts\DeliveryRate;
use Commerz\Shipping\Rates\Data\RateSpec;
use Commerz\Shipping\Tests\Fixtures\BrokenLoadingRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Packman\Plugins\Contracts\Contributions\Contributions;

require_once __DIR__.'/Helpers.php';

uses(RefreshDatabase::class, OpEnvironment::class);

beforeEach(function (): void {
    $this->golden = opGolden();
    $this->web = $this->golden['channel'];
});

it('explains every method that is not archived, by code', function (): void {
    expect(opVerdicts(opRequest($this->web)))->toBe([
        'courier' => 'available 250.00',
        'express' => 'not_in_channel',
        'pickup' => 'available 0.00',
        'post' => 'method_disabled',
    ]);
});

it('names the zone and price of an available method', function (): void {
    $verdict = opVerdict(opRequest($this->web), 'courier');

    expect([$verdict->isAvailable(), $verdict->zoneCode, $verdict->price?->toDecimal(), $verdict->message])
        ->toBe([true, 'msk', '250.00', 'available']);
});

it('says when the threshold made delivery free', function (): void {
    expect(opVerdict(opRequest($this->web, value: '5000.00'), 'courier')->message)->toBe('free above the threshold');
});

it('explains an address no zone covers', function (): void {
    expect(opVerdicts(opRequest($this->web, null, country: 'KZ')))->toMatchArray(['courier' => 'no_zone', 'pickup' => 'no_zone']);
});

it('explains a method without tariffs as no_zone', function (): void {
    mtMethod('bare', ['channels' => [$this->web]]);

    expect(opVerdict(opRequest($this->web), 'bare')->verdict)->toBe('no_zone');
});

it('explains a currency without a tariff', function (): void {
    expect(opVerdict(opRequest($this->web, value: '1.00', currency: 'USD'), 'courier')->verdict)->toBe('no_rate_for_currency');
});

it('explains a carrier that is no longer installed', function (): void {
    DB::table(mtTable('shipping_methods'))->where('code', 'pickup')->update(['carrier' => 'gone']);

    expect(opVerdict(opRequest($this->web), 'pickup')->verdict)->toBe('unsupported_carrier');
});

it('explains a stored rate that cannot be loaded', function (): void {
    DB::table(mtTable('shipping_rates'))->where('shipping_method_id', $this->golden['methods']['pickup']->id->value)->update(['params' => '{"amount": "lots"}']);

    $verdict = opVerdict(opRequest($this->web), 'pickup');

    expect($verdict->verdict)->toBe('unsupported_rate')
        ->and($verdict->zoneCode)->toBe('ru');
});

it('explains a rate plugin that failed', function (): void {
    mtMethod('flaky', ['channels' => [$this->web], 'rates' => [mtRate($this->golden['zones']['ru']->id, ['rate' => new RateSpec('test.throwing')])]]);

    expect(opVerdict(opRequest($this->web), 'flaky')->message)->toContain('the rate service is down');
});

it('keeps healthy options and admin reads available when a stored tariff plugin crashes', function (): void {
    $method = mtMethod('broken', ['channels' => [$this->web], 'rates' => [mtRate($this->golden['zones']['ru']->id, ['rate' => new RateSpec('test.broken_loading')])]]);
    $rate = app(Contributions::class)->get(DeliveryRate::KIND, 'test.broken_loading', DeliveryRate::class);
    expect($rate)->toBeInstanceOf(BrokenLoadingRate::class);
    $rate->failOn = 'normalize';

    expect(opVerdict(opRequest($this->web), 'broken')->verdict)->toBe('unsupported_rate')
        ->and(array_keys(opOptions(opRequest($this->web))))->toBe(['courier', 'pickup'])
        ->and(mtMethods()->find($method->id)->rates[0]->unsupported)->toContain('normalize failed');
});

it('passes on the reason a rate gave', function (): void {
    expect(opVerdict(opRequest($this->web, 'RU-SPE', 5001), 'courier')->verdict)->toBe('weight_out_of_range');
});

it('explains a disabled method and one outside the channel', function (): void {
    expect(opVerdicts(opRequest($this->web)))->toMatchArray(['post' => 'method_disabled', 'express' => 'not_in_channel']);
});

it('explains a method code that does not exist or is archived as method_not_found', function (): void {
    $pricer = app(MethodPricer::class);
    mtMethods()->archive(new ArchiveMethod($this->golden['methods']['pickup']->id));

    expect(array_map(static fn (PricedMethod $m): string => $m->verdict, $pricer->price(opRequest($this->web), 'nope')))->toBe(['method_not_found'])
        ->and(array_map(static fn (PricedMethod $m): string => $m->verdict, $pricer->price(opRequest($this->web), 'pickup')))->toBe(['method_not_found'])
        ->and(opVerdicts(opRequest($this->web)))->not->toHaveKey('pickup');
});

it('checks the disabled state before the channel, and the channel before the carrier', function (): void {
    DB::table(mtTable('shipping_methods'))->where('code', 'express')->update(['carrier' => 'gone', 'status' => 'disabled']);
    DB::table(mtTable('shipping_methods'))->where('code', 'courier')->update(['carrier' => 'gone']);
    DB::table(mtTable('shipping_method_channels'))->where('shipping_method_id', $this->golden['methods']['courier']->id->value)->delete();

    expect(opVerdicts(opRequest($this->web)))->toMatchArray(['express' => 'method_disabled', 'courier' => 'not_in_channel']);
});
