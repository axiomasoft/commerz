<?php

declare(strict_types=1);

use Commerz\Context\CommerceContext;
use Commerz\Errors\InvalidConfiguration;
use Commerz\Errors\UnsupportedContribution;
use Commerz\Identity\CustomerRef;
use Commerz\Identity\SellableRef;
use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Plugins\Contracts\CommerzPlugin;
use Commerz\Plugins\PluginManifest;
use Commerz\Plugins\PluginRegistrar;
use Commerz\Ports\Customers\Contracts\CustomerDirectory;
use Commerz\Ports\Customers\Data\CustomerProfile;
use Commerz\Ports\Customers\Internal\GuestOnlyDirectory;
use Commerz\Ports\Delivery\Contracts\DeliveryOptions;
use Commerz\Ports\Delivery\Contracts\ShipmentBooking;
use Commerz\Ports\Delivery\Data\Address;
use Commerz\Ports\Delivery\Data\BookShipment;
use Commerz\Ports\Delivery\Data\DeliveryRequest;
use Commerz\Ports\Delivery\Data\Parcel;
use Commerz\Ports\Prices\Contracts\PriceResolver;
use Commerz\Ports\Prices\Internal\SnapshotPriceResolver;
use Commerz\Ports\Promotions\Contracts\PromotionUsage;
use Commerz\Ports\Promotions\Data\AppliedPromotion;
use Commerz\Ports\Promotions\Data\ConsumePromotionUsage;
use Commerz\Ports\Promotions\Internal\NullPromotionUsage;
use Commerz\Ports\Sellables\Contracts\Sellables;
use Commerz\Ports\Stock\Contracts\StockAvailability;
use Commerz\Ports\Stock\Contracts\StockReservations;
use Commerz\Ports\Stock\Internal\UnlimitedStock;
use Commerz\Stores\Data\ChannelId;
use Commerz\Testkit\Fakes\FakePrices;
use Commerz\Testkit\Fakes\FakeSellables;

final class PdHostDirectory implements CustomerDirectory
{
    public function find(CustomerRef $customer): ?CustomerProfile
    {
        return new CustomerProfile($customer, ['vip']);
    }
}

final class PdSellablesPlugin implements CommerzPlugin
{
    public function manifest(): PluginManifest
    {
        return new PluginManifest('test.sellables', '1.0.0');
    }

    public function register(PluginRegistrar $registrar): void
    {
        $registrar->sellableProvider(new FakeSellables('test.thing')->add(
            FakeSellables::snapshot('test.thing:a'),
            FakeSellables::snapshot('test.thing:b'),
        ));
        $registrar->sellableProvider(new FakeSellables('other')->add(FakeSellables::snapshot('other:x')));
    }
}

function pdContext(): CommerceContext
{
    return CommerceContext::for(ChannelId::generate(), Currency::of('RUB'));
}

it('binds the core defaults when no package implements a port', function (): void {
    config(['commerz.stock' => 'unlimited']);

    expect(app(PriceResolver::class))->toBeInstanceOf(SnapshotPriceResolver::class)
        ->and(app(PromotionUsage::class))->toBeInstanceOf(NullPromotionUsage::class)
        ->and(app(CustomerDirectory::class))->toBeInstanceOf(GuestOnlyDirectory::class)
        ->and(app(StockAvailability::class))->toBeInstanceOf(UnlimitedStock::class)
        ->and(app(StockReservations::class))->toBeInstanceOf(UnlimitedStock::class)
        ->and(app(DeliveryOptions::class)->options(new DeliveryRequest(pdContext(), new Address('RU'), new Parcel(100, Money::zero('RUB')))))->toBe([])
        ->and(app(CustomerDirectory::class)->find(CustomerRef::of('user', '1')))->toBeNull();
});

it('fails inventory stock mode without an inventory package on first use', function (): void {
    expect(fn () => app(StockAvailability::class))->toThrow(InvalidConfiguration::class, 'commerz.stock')
        ->and(fn () => app(StockReservations::class))->toThrow(InvalidConfiguration::class, 'commerz-inventory');
});

it('lets a feature package override a default with a plain bind', function (): void {
    app()->singleton(PriceResolver::class, FakePrices::class);

    expect(app(PriceResolver::class))->toBeInstanceOf(FakePrices::class);
});

it('uses the configured customer directory', function (): void {
    config(['commerz.customers' => PdHostDirectory::class]);

    expect(app(CustomerDirectory::class)->find(CustomerRef::of('user', '1'))?->groups)->toBe(['vip']);
});

it('refuses a configured customer class that is not a directory', function (): void {
    config(['commerz.customers' => stdClass::class]);

    expect(fn () => app(CustomerDirectory::class))->toThrow(InvalidConfiguration::class, 'commerz.customers');
});

it('refuses to drop promotion usage it cannot count', function (): void {
    $usage = app(PromotionUsage::class);
    $usage->consume(new ConsumePromotionUsage('order:1', null, []));

    expect(fn () => $usage->consume(new ConsumePromotionUsage('order:2', null, [new AppliedPromotion('p1', null, Money::of('1.00', 'RUB'))])))
        ->toThrow(InvalidConfiguration::class, 'PromotionUsage');
});

it('cannot book shipments without a shipping package', function (): void {
    expect(fn () => app(ShipmentBooking::class)->book(new BookShipment('fulfillment:1', 'cdek', 'courier', new Address('RU'), new Parcel(1, Money::zero('RUB')))))
        ->toThrow(UnsupportedContribution::class, 'carrier/cdek');
});

it('routes sellables to providers by ref type, in request order, once per ref', function (): void {
    config(['commerz.plugins' => [PdSellablesPlugin::class]]);
    $refs = [SellableRef::of('test.thing', 'b'), SellableRef::of('other', 'x'), SellableRef::of('test.thing', 'a'), SellableRef::of('test.thing', 'b')];

    $resolved = app(Sellables::class)->resolveMany($refs, pdContext());

    expect(array_keys($resolved))->toBe(['test.thing:b', 'other:x', 'test.thing:a'])
        ->and($resolved['other:x']->sku)->toBe('X')
        ->and(app(Sellables::class)->resolveMany([], pdContext()))->toBe([]);
});
