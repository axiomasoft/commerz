<?php

declare(strict_types=1);

use Commerz\CommerzServiceProvider;
use Commerz\Measures\LocalizedText;
use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Plugins\Contracts\CommerzPlugin;
use Commerz\Plugins\PluginManifest;
use Commerz\Plugins\PluginRegistrar;
use Commerz\Ports\Delivery\Contracts\DeliveryOptions;
use Commerz\Ports\Sellables\Enums\SellableKind;
use Commerz\Sales\Carts\Data\EnterCode;
use Commerz\Sales\Carts\Data\SelectDelivery;
use Commerz\Sales\Internal\SalesPlugin;
use Commerz\Sales\SalesServiceProvider;
use Commerz\Sales\Tests\Fixtures\PromotionAdjustmentStage;
use Commerz\Sales\Tests\TestCase as SalesTestCase;
use Commerz\Shipping\Methods\Contracts\Methods;
use Commerz\Shipping\Methods\Data\CreateMethod;
use Commerz\Shipping\Methods\Data\ZoneRate;
use Commerz\Shipping\Options\Internal\DatabaseShippingOptions;
use Commerz\Shipping\Rates\Data\RateSpec;
use Commerz\Shipping\ShippingServiceProvider;
use Commerz\Shipping\Zones\Contracts\Zones;
use Commerz\Shipping\Zones\Data\CreateZone;
use Commerz\Shipping\Zones\Data\ZoneMember;
use Commerz\Testkit\Commerce;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/../../packages/sales/tests/Feature/Helpers.php';

trait SalesShippingEnvironment
{
    protected function commerzProviders(): array
    {
        return [CommerzServiceProvider::class, SalesServiceProvider::class, ShippingServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('commerz.plugins', [SalesShippingDiscountPlugin::class]);
        $app['config']->set('commerz.stock', 'unlimited');
    }
}

final class SalesShippingDiscountPlugin implements CommerzPlugin
{
    public function manifest(): PluginManifest
    {
        return new PluginManifest('tests.sales-shipping', '1.0.0', [SalesPlugin::ID]);
    }

    public function register(PluginRegistrar $registrar): void
    {
        $registrar->quoteStage(PromotionAdjustmentStage::class);
    }
}

uses(SalesTestCase::class, SalesShippingEnvironment::class, RefreshDatabase::class);

it('shows the same delivery tariff as the selected-method quote for a mixed cart after order discounts', function (SellableKind $kind, bool $discount, string $threshold, string $price): void {
    $fake = Commerce::fake();
    $fake->sellables->add(slsSnapshot('fake:a', '100.00'), slsSnapshot('fake:extra', '300.00', $kind));
    app()->forgetInstance(DeliveryOptions::class);
    app()->singleton(DeliveryOptions::class, DatabaseShippingOptions::class);
    $channel = slsChannel();
    $zone = app(Zones::class)->create(new CreateZone('ru', 'Russia', [ZoneMember::country('RU')]));
    app(Methods::class)->create(new CreateMethod('courier', new LocalizedText(['ru' => 'Courier']), 'manual', [$channel], [
        new ZoneRate($zone->id, Currency::of('RUB'), new RateSpec('flat', ['amount' => Money::of('50.00', 'RUB')]), Money::of($threshold, 'RUB')),
    ]));
    $cart = slsCartWith($channel, ['fake:a' => 1, 'fake:extra' => 1]);

    if ($discount) {
        slsCarts()->enterCode(new EnterCode($cart->id, 'promo:p1'));
    }

    $shown = slsCarts()->deliveryOptions($cart->id)[0]->price;
    slsCarts()->selectDelivery(new SelectDelivery($cart->id, 'courier'));
    $quote = slsCarts()->quote($cart->id);

    expect($shown)->toEqual(Money::of($price, 'RUB'))
        ->and($quote->delivery?->price)->toEqual($shown)
        ->and(slsPlace($cart)->delivery?->price)->toEqual($shown);
})->with([
    'physical and service' => [SellableKind::Service, false, '250.00', '0.00'],
    'physical and digital' => [SellableKind::Digital, false, '250.00', '0.00'],
    'order discount moves below free-delivery threshold' => [SellableKind::Service, true, '395.00', '50.00'],
]);
