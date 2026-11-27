<?php

declare(strict_types=1);

// Shared fixtures of the method tests.

use Commerz\Configuration\CommerzOptions;
use Commerz\Errors\CommerzException;
use Commerz\Measures\LocalizedText;
use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Ports\Delivery\Enums\DeliveryMode;
use Commerz\Schema\TableNames;
use Commerz\Shipping\Methods\Contracts\Methods;
use Commerz\Shipping\Methods\Data\CreateMethod;
use Commerz\Shipping\Methods\Data\MethodView;
use Commerz\Shipping\Methods\Data\ZoneRate;
use Commerz\Shipping\Methods\Enums\MethodStatus;
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
use Illuminate\Testing\PendingCommand;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Packman\Errors\Exceptions\CodedException;
use Packman\Plugins\Contracts\Contributions\Contributions;
use Packman\Plugins\Contracts\Registry\PluginInspector;
use Pest\TestSuite;

function mtChannel(string $code = 'web', string $currency = 'RUB'): ChannelId
{
    $existing = DB::table('cmz_channels')->where('code', $code)->value('id');

    if (is_string($existing)) {
        return new ChannelId($existing);
    }

    $store = DB::table('cmz_stores')->where('code', 'main')->value('id');

    if (! is_string($store)) {
        $store = app(Stores::class)->create(new CreateStore('main', 'Main', Currency::of('RUB')))->id->value;
    }

    return app(Channels::class)->create(new CreateChannel(new StoreId($store), $code, ucfirst($code), [Currency::of($currency)]))->id;
}

/** @param  list<ZoneMember>|null  $members */
function mtZone(string $code = 'ru', ?array $members = null): ZoneView
{
    return app(Zones::class)->create(new CreateZone($code, strtoupper($code), $members ?? [ZoneMember::country('RU')]));
}

function mtFlat(string $amount = '300.00', string $currency = 'RUB'): RateSpec
{
    return new RateSpec('flat', ['amount' => Money::of($amount, $currency)]);
}

/**
 * Named arguments of ZoneRate that replace the defaults.
 *
 * @param  array{zone?: ZoneId, currency?: Currency, rate?: RateSpec, freeAbove?: Money|null, taxIncluded?: bool|null, etaDays?: int|null}  $with
 */
function mtRate(ZoneId $zone, array $with = []): ZoneRate
{
    return new ZoneRate(
        $with['zone'] ?? $zone,
        $with['currency'] ?? Currency::of('RUB'),
        $with['rate'] ?? mtFlat(),
        $with['freeAbove'] ?? null,
        $with['taxIncluded'] ?? null,
        $with['etaDays'] ?? null,
    );
}

/**
 * Named arguments of the command that replace the defaults.
 *
 * @param  array{name?: LocalizedText, carrier?: string, channels?: list<ChannelId>, rates?: list<ZoneRate>, position?: int, status?: MethodStatus, mode?: DeliveryMode}  $with
 */
function mtCommand(string $code = 'courier', array $with = []): CreateMethod
{
    return new CreateMethod(
        $code,
        $with['name'] ?? LocalizedText::of('en', ucfirst($code)),
        $with['carrier'] ?? 'manual',
        $with['channels'] ?? [],
        $with['rates'] ?? [],
        $with['position'] ?? 0,
        $with['status'] ?? MethodStatus::Active,
        $with['mode'] ?? DeliveryMode::Shipping,
    );
}

/**
 * @param  array{name?: LocalizedText, carrier?: string, channels?: list<ChannelId>, rates?: list<ZoneRate>, position?: int, status?: MethodStatus, mode?: DeliveryMode}  $with
 */
function mtMethod(string $code = 'courier', array $with = []): MethodView
{
    return mtMethods()->create(mtCommand($code, $with));
}

function mtMethods(): Methods
{
    return app(Methods::class);
}

/**
 * The error code (and field, when it has one) a call fails with.
 *
 * @param  Closure(): mixed  $call
 */
function mtFailure(Closure $call): string
{
    try {
        $call();
    } catch (CommerzException $e) {
        $field = property_exists($e, 'field') ? $e->field : null;

        return $e->code().(is_string($field) ? " @ {$field}" : '');
    } catch (CodedException $e) {
        return $e->errorDescriptor()->errorCode->value();
    }

    return 'no error';
}

/** Runs the test migrations and fails when migrate does not succeed. */
function mtMigrate(): void
{
    $test = TestSuite::getInstance()->test;

    if (! $test instanceof OrchestraTestCase) {
        throw new RuntimeException('migrate must run inside a test.');
    }

    $pending = $test->artisan('migrate');

    if (! $pending instanceof PendingCommand) {
        throw new RuntimeException('migrate did not return a pending command.');
    }

    $pending->assertSuccessful();
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
