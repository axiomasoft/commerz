<?php

declare(strict_types=1);

// The golden fixture of knowledge/shipping-design#golden, shared by the option, explain and delivery stage tests.

use Commerz\Context\CommerceContext;
use Commerz\Facades\Commerz;
use Commerz\Measures\LocalizedText;
use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Plugins\Contracts\CommerzPlugin;
use Commerz\Plugins\PluginManifest;
use Commerz\Plugins\PluginRegistrar;
use Commerz\Ports\Delivery\Data\Address;
use Commerz\Ports\Delivery\Data\DeliveryOption;
use Commerz\Ports\Delivery\Data\DeliveryRequest;
use Commerz\Ports\Delivery\Data\Parcel;
use Commerz\Ports\Sellables\Enums\SellableKind;
use Commerz\Quotes\Data\DeliverySelection;
use Commerz\Quotes\Data\QuoteInput;
use Commerz\Quotes\Data\QuoteLineInput;
use Commerz\Quotes\Data\QuoteResult;
use Commerz\Shipping\Facades\Shipping;
use Commerz\Shipping\Internal\ShippingPlugin;
use Commerz\Shipping\Methods\Data\DisableMethod;
use Commerz\Shipping\Methods\Data\MethodView;
use Commerz\Shipping\Methods\Data\ZoneRate;
use Commerz\Shipping\Options\Data\MethodVerdict;
use Commerz\Shipping\Rates\Contracts\DeliveryRate;
use Commerz\Shipping\Rates\Data\RateSpec;
use Commerz\Shipping\Tests\Fixtures\FixedRate;
use Commerz\Shipping\Tests\Fixtures\ForeignCurrencyRate;
use Commerz\Shipping\Tests\Fixtures\OrderDiscountStage;
use Commerz\Shipping\Tests\Fixtures\ThrowingRate;
use Commerz\Shipping\Zones\Data\ZoneMember;
use Commerz\Shipping\Zones\Data\ZoneView;
use Commerz\Stores\Contracts\Channels;
use Commerz\Stores\Contracts\Stores;
use Commerz\Stores\Data\ChannelId;
use Commerz\Stores\Data\CreateChannel;
use Commerz\Stores\Data\CreateStore;
use Commerz\Testkit\Fakes\FakeSellables;
use Illuminate\Foundation\Application;

require_once __DIR__.'/../Methods/Helpers.php';

/** Registers the fixture rates and the order-discount stage before the application boots. */
trait OpEnvironment
{
    /** @param Application $app */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('commerz.plugins', [OpPlugin::class]);
    }
}

final class OpPlugin implements CommerzPlugin
{
    public function manifest(): PluginManifest
    {
        return new PluginManifest('test.shipping-options', '1.0.0', [ShippingPlugin::ID]);
    }

    public function register(PluginRegistrar $registrar): void
    {
        $registrar->contribute(DeliveryRate::KIND, ThrowingRate::class);
        $registrar->contribute(DeliveryRate::KIND, ForeignCurrencyRate::class);
        $registrar->contribute(DeliveryRate::KIND, FixedRate::class);
        $registrar->quoteStage(OrderDiscountStage::class);
    }
}

/**
 * Channel `web` (RUB, USD), zones `ru`, `msk`, `msk-center` (postal 101), `spb`, and the methods `courier`, `pickup`,
 * `post` (disabled) and `express` (not in the channel).
 *
 * @return array{channel: ChannelId, zones: array<string, ZoneView>, methods: array<string, MethodView>}
 */
function opGolden(): array
{
    $store = app(Stores::class)->create(new CreateStore('main', 'Main', Currency::of('RUB')));
    $web = app(Channels::class)->create(new CreateChannel($store->id, 'web', 'Web', [Currency::of('RUB'), Currency::of('USD')]))->id;

    $zones = [
        'ru' => mtZone('ru', [ZoneMember::country('RU')]),
        'msk' => mtZone('msk', [ZoneMember::region('RU-MOW')]),
        'msk-center' => mtZone('msk-center', [ZoneMember::postalPrefix('RU', '101')]),
        'spb' => mtZone('spb', [ZoneMember::region('RU-SPE')]),
    ];

    $methods = [
        'courier' => mtMethod('courier', [
            'name' => new LocalizedText(['ru' => 'Курьер', 'en' => 'Courier']),
            'channels' => [$web],
            'position' => 10,
            'rates' => opCourierRates($zones),
        ]),
        'pickup' => mtMethod('pickup', ['channels' => [$web], 'position' => 20, 'rates' => [mtRate($zones['ru']->id, ['rate' => mtFlat('0.00')])]]),
        'post' => mtMethod('post', ['channels' => [$web], 'position' => 30, 'rates' => [mtRate($zones['ru']->id, ['rate' => mtFlat('150.00')])]]),
        'express' => mtMethod('express', ['position' => 5, 'rates' => [mtRate($zones['ru']->id, ['rate' => mtFlat('900.00')])]]),
    ];

    mtMethods()->disable(new DisableMethod($methods['post']->id));

    return ['channel' => $web, 'zones' => $zones, 'methods' => $methods];
}

/**
 * The tariffs of `courier`; `$msk` replaces named arguments of the `msk` tariff.
 *
 * @param  array<string, ZoneView>  $zones
 * @param  array<string, mixed>  $msk
 * @return list<ZoneRate>
 */
function opCourierRates(array $zones, array $msk = []): array
{
    return [
        mtRate($zones['ru']->id, ['rate' => new RateSpec('weight_table', ['steps' => [
            ['up_to_grams' => 1000, 'amount' => Money::of('300.00', 'RUB')],
            ['up_to_grams' => 5000, 'amount' => Money::of('500.00', 'RUB')],
        ]]), 'etaDays' => 3]),
        mtRate($zones['msk']->id, array_merge(['rate' => mtFlat('250.00'), 'freeAbove' => Money::of('5000.00', 'RUB'), 'etaDays' => 1], $msk)),
        mtRate($zones['msk-center']->id, ['rate' => mtFlat('200.00')]),
    ];
}

function opContext(ChannelId $channel, string $currency = 'RUB', string $locale = 'ru'): CommerceContext
{
    return CommerceContext::for($channel, Currency::of($currency), $locale, new DateTimeImmutable('2026-10-09 12:00:00', new DateTimeZone('UTC')));
}

function opRequest(
    ChannelId $channel,
    ?string $region = 'RU-MOW',
    int $grams = 800,
    string $value = '3000.00',
    ?string $postal = null,
    string $currency = 'RUB',
    string $locale = 'ru',
    string $country = 'RU',
): DeliveryRequest {
    return new DeliveryRequest(
        opContext($channel, $currency, $locale),
        new Address($country, $region, postalCode: $postal),
        new Parcel($grams, Money::of($value, $currency)),
    );
}

/** @return list<string> `code price zone eta` of each option, in list order */
function opList(DeliveryRequest $request): array
{
    return array_map(
        static fn (DeliveryOption $option): string => "{$option->methodCode} {$option->price->toDecimal()} {$option->meta['zone']} ".($option->etaDays ?? '-'),
        Shipping::options()->list($request),
    );
}

/** @return array<string, DeliveryOption> the listed options by method code */
function opOptions(DeliveryRequest $request): array
{
    $options = [];

    foreach (Shipping::options()->list($request) as $option) {
        $options[$option->methodCode] = $option;
    }

    return $options;
}

/** @return array<string, string> `verdict` (and ` price` when available) by method code */
function opVerdicts(DeliveryRequest $request): array
{
    $verdicts = [];

    foreach (Shipping::options()->explain($request)->methods as $verdict) {
        $verdicts[$verdict->methodCode] = $verdict->verdict.($verdict->price === null ? '' : " {$verdict->price->toDecimal()}");
    }

    return $verdicts;
}

function opVerdict(DeliveryRequest $request, string $method): MethodVerdict
{
    return Shipping::options()->explain($request)->method($method) ?? throw new RuntimeException("no verdict for '{$method}'");
}

/**
 * A quote of lines priced `$prices` (line ids 1, 2, …) with the delivery selection.
 *
 * @param  list<string>  $prices
 * @param  list<string>  $codes
 */
function opQuote(
    ChannelId $channel,
    array $prices,
    ?string $method = 'courier',
    string $region = 'RU-MOW',
    int $grams = 800,
    array $codes = [],
    string $locale = 'ru',
    bool $physical = true,
    string $declaredValue = '0.00',
): QuoteResult {
    $lines = [];

    foreach ($prices as $index => $price) {
        $id = (string) ($index + 1);
        $kind = $physical ? SellableKind::Physical : SellableKind::Digital;
        $lines[] = new QuoteLineInput($id, FakeSellables::snapshot("item-{$id}", Money::of($price, 'RUB'), $kind), 1);
    }

    $delivery = $method === null ? null : new DeliverySelection($method, new Address('RU', $region), new Parcel($grams, Money::of($declaredValue, 'RUB')));

    return Commerz::quotes()->run(new QuoteInput(opContext($channel, locale: $locale), $lines, $codes, $delivery));
}
