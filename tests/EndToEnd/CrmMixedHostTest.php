<?php

declare(strict_types=1);

use Commerz\Context\CommerceContext;
use Commerz\EndToEnd\Fixtures\CrmCustomers;
use Commerz\EndToEnd\Fixtures\CrmPlugin;
use Commerz\EndToEnd\Fixtures\CrmServices;
use Commerz\EndToEnd\Support\EndToEndTestCase;
use Commerz\EndToEnd\Support\PortMatrix;
use Commerz\EndToEnd\Support\ShopWorld;
use Commerz\Inventory\Facades\Inventory;
use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Ports\Delivery\Data\Address;
use Commerz\Sales\Carts\Data\AddCartLine;
use Commerz\Sales\Carts\Data\CreateCart;
use Commerz\Sales\Carts\Data\SelectDelivery;
use Commerz\Sales\Carts\Data\SetCartAddress;
use Commerz\Sales\Carts\Enums\AddressRole;
use Commerz\Sales\Checkout\Data\PlaceOrder;
use Commerz\Sales\Facades\Sales;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;

// A host that runs the whole family and sells its CRM services too: the router serves `variant` from the catalog and
// `crm.service` from the host side by side in one cart, and only the catalog's goods are reserved and shipped.

trait CrmMixedHostEnvironment
{
    /** @param  Application  $app */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('commerz.plugins', [CrmPlugin::class]);
        $app['config']->set('commerz.customers', CrmCustomers::class);
    }
}

uses(CrmMixedHostEnvironment::class, RefreshDatabase::class);

abstract class CrmMixedHostCase extends EndToEndTestCase
{
    use CrmMixedHostEnvironment;
}

it('places a cart of a catalog variant and a host service and reserves only the variant', function (): void {
    $world = ShopWorld::build();
    $cart = Sales::carts()->create(new CreateCart(CommerceContext::for($world->channel, Currency::of('RUB'), 'ru')))->id;
    Sales::carts()->addLine(new AddCartLine($cart, $world->small, 1));
    Sales::carts()->addLine(new AddCartLine($cart, CrmServices::ref('consulting'), 1));
    Sales::carts()->setAddress(new SetCartAddress($cart, AddressRole::Shipping, new Address('RU', 'RU-MOW', 'Moscow', '101000', ['Tverskaya 1'], 'Ivan', '+70000000000')));
    Sales::carts()->selectDelivery(new SelectDelivery($cart, ShopWorld::COURIER));

    // S 1000.00 (retail) + consulting 1500.00 (no retail price: the host's tariff) = 2500.00; −10% = −250.00 shared
    // −100.00/−150.00; courier 300.00. VAT 20% included: 900.00 → 150.00, 1350.00 → 225.00, delivery 50.00 → 425.00.
    // Total 2250.00 + 300.00 = 2550.00.
    $quote = Sales::carts()->quote($cart);
    [$variant, $service] = $quote->lines;

    expect([$variant->sellable->kind->value, $variant->sellable->requiresShipping, $variant->priceSource])->toBe(['physical', true, 'price_list:retail'])
        ->and([$service->sellable->kind->value, $service->sellable->requiresShipping, $service->priceSource])->toBe(['service', false, 'sellable'])
        ->and($quote->totals->taxTotal)->toEqual(Money::of('425.00', 'RUB'))
        ->and($quote->total)->toEqual(Money::of('2550.00', 'RUB'));

    $order = Sales::checkout()->place(new PlaceOrder($cart, $quote->total));
    $reservation = Inventory::reservations()->find('order:'.$order->id->value);

    expect(PortMatrix::sellableTypes())->toBe(['variant', CrmServices::KEY])
        ->and($order->total)->toEqual($quote->total)
        ->and(array_map(static fn ($line): array => [(string) $line->sellable, $line->units], $reservation->lines))->toBe([[(string) $world->small, 1]])
        ->and(Inventory::stock()->level($world->smallItem, $world->location)->available)->toBe(4);
});
