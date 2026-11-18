<?php

declare(strict_types=1);

// Shared fixtures of the method tests.

use Commerz\Configuration\CommerzOptions;
use Commerz\Errors\CommerzException;
use Commerz\Measures\LocalizedText;
use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Shipping\Methods\Contracts\Methods;
use Commerz\Shipping\Methods\Data\CreateMethod;
use Commerz\Shipping\Methods\Data\MethodView;
use Commerz\Shipping\Methods\Data\ZoneRate;
use Commerz\Shipping\Methods\Internal\MethodRows;
use Commerz\Shipping\Rates\Data\RateSpec;
use Commerz\Shipping\Rates\Internal\RateCodec;
use Commerz\Shipping\Shipping;
use Commerz\Shipping\Zones\Contracts\Zones;
use Commerz\Shipping\Zones\Data\CreateZone;
use Commerz\Shipping\Zones\Data\ZoneId;
use Commerz\Shipping\Zones\Data\ZoneMember;
use Commerz\Shipping\Zones\Data\ZoneView;
use Commerz\Stores\Contracts\Channels;
use Commerz\Stores\Contracts\Stores;
use Commerz\Stores\Data\ChannelId;
use Commerz\Stores\Data\CreateChannel;
use Commerz\Stores\Data\CreateStore;
use Commerz\Stores\Data\StoreId;
use Illuminate\Support\Facades\DB;
use Packman\Errors\Exceptions\CodedException;
use Packman\Plugins\Contracts\Contributions\Contributions;
use Packman\Plugins\Contracts\Registry\PluginInspector;
use Packman\Schema\Data\Compiler\TableNames;

function mtChannel(string $code = 'web', string $currency = 'RUB'): ChannelId
{
    $existing = DB::table('cmz_channels')->where('code', $code)->value('id');

    if (is_string($existing)) {
        return new ChannelId($existing);
    }

    $store = DB::table('cmz_stores')->where('code', 'main')->value('id')
        ?? app(Stores::class)->create(new CreateStore('main', 'Main', Currency::of('RUB')))->id->value;

    return app(Channels::class)->create(new CreateChannel(new StoreId($store), $code, ucfirst($code), [Currency::of($currency)]))->id;
}

function mtZone(string $code = 'ru', ?array $members = null): ZoneView
{
    return app(Zones::class)->create(new CreateZone($code, strtoupper($code), $members ?? [ZoneMember::country('RU')]));
}

function mtFlat(string $amount = '300.00', string $currency = 'RUB'): RateSpec
{
    return new RateSpec('flat', ['amount' => Money::of($amount, $currency)]);
}

/** @param  array<string, mixed>  $with  named arguments of ZoneRate that replace the defaults */
function mtRate(ZoneId $zone, array $with = []): ZoneRate
{
    return new ZoneRate(...array_merge(['zone' => $zone, 'currency' => Currency::of('RUB'), 'rate' => mtFlat()], $with));
}

/** @param  array<string, mixed>  $with  named arguments of the command that replace the defaults */
function mtCommand(string $code = 'courier', array $with = []): CreateMethod
{
    return new CreateMethod(...array_merge([
        'code' => $code,
        'name' => LocalizedText::of('en', ucfirst($code)),
        'carrier' => 'manual',
    ], $with));
}

/** @param  array<string, mixed>  $with */
function mtMethod(string $code = 'courier', array $with = []): MethodView
{
    return mtMethods()->create(mtCommand($code, $with));
}

function mtMethods(): Methods
{
    return app(Methods::class);
}

/** The error code (and field, when it has one) a call fails with. */
function mtFailure(Closure $call): string
{
    try {
        $call();
    } catch (CommerzException $e) {
        return $e->code().(property_exists($e, 'field') ? " @ {$e->field}" : '');
    } catch (CodedException $e) {
        return $e->errorDescriptor()->errorCode->value();
    }

    return 'no error';
}

function mtTable(string $logical): string
{
    return app(TableNames::class)->table($logical);
}

/** @param  list<class-string>  $plugins */
function mtUsePlugins(array $plugins): void
{
    config(['commerz.plugins' => $plugins, 'packman.plugins' => $plugins]);

    // Simulate a fresh worker without changing the test database. Resolve aliases
    // through the public container API so the shared Packman singleton is reset too.
    foreach ([CommerzOptions::class, Contributions::class, PluginInspector::class, RateCodec::class, MethodRows::class, Methods::class, Shipping::class] as $abstract) {
        app()->forgetInstance(app()->getAlias($abstract));
    }
}
