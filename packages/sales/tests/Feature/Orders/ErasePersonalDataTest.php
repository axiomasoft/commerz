<?php

declare(strict_types=1);

use Commerz\Identity\CustomerRef;
use Commerz\Ports\Customers\Data\CustomerProfile;
use Commerz\Ports\Delivery\Data\Address;
use Commerz\Ports\Privacy\Events\CustomerPersonalDataErased;
use Commerz\Sales\Carts\Data\SetCartAddress;
use Commerz\Sales\Carts\Data\SetCartCustomer;
use Commerz\Sales\Carts\Enums\AddressRole;
use Commerz\Testkit\Commerce;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

require_once __DIR__.'/../Helpers.php';

uses(RefreshDatabase::class, SlsEnvironment::class);

beforeEach(function (): void {
    Commerce::fake()->sellables->add(slsSnapshot('fake:a', '100.00'));
    slsConfig(['checkout' => ['require_delivery' => false]]);
    $this->channel = slsChannel();
});

it('anonymizes personal data for a customer while preserving accounting invariants', function (): void {
    Event::fake([CustomerPersonalDataErased::class]);

    $target = CustomerRef::of('crm', 'gdpr-1');
    $other = CustomerRef::of('crm', 'keep-2');

    Commerce::fake()->customers->add(new CustomerProfile($target, 'alice@example.com', 'Alice Doe'));
    Commerce::fake()->customers->add(new CustomerProfile($other, 'bob@example.com', 'Bob Roe'));

    $shipping = new Address('RU', 'RU-MOW', 'Moscow', '101000', ['Tverskaya 1', 'Apt 12'], 'Alice Doe', '+79990001122');
    $billing = new Address('DE', 'DE-BE', 'Berlin', '10115', ['Torstrasse 5'], 'Alice Doe', '+4930123456');

    $cart = slsCartWith($this->channel, ['fake:a' => 2]);
    slsCarts()->setCustomer(new SetCartCustomer($cart->id, $target));
    slsCarts()->setAddress(new SetCartAddress($cart->id, AddressRole::Shipping, $shipping));
    slsCarts()->setAddress(new SetCartAddress($cart->id, AddressRole::Billing, $billing));
    $order = slsPlace($cart);

    $openCart = slsCartWith($this->channel, ['fake:a' => 1]);
    slsCarts()->setCustomer(new SetCartCustomer($openCart->id, $target));
    slsCarts()->setAddress(new SetCartAddress($openCart->id, AddressRole::Shipping, $shipping));

    $otherCart = slsCartWith($this->channel, ['fake:a' => 1]);
    slsCarts()->setCustomer(new SetCartCustomer($otherCart->id, $other));
    slsCarts()->setAddress(new SetCartAddress($otherCart->id, AddressRole::Shipping, $shipping));
    $otherOrder = slsPlace($otherCart);

    $erased = slsOrders()->erasePersonalData($target);

    expect($erased)->toBe(1);

    $anonymizedOrder = slsOrders()->find($order->id);
    expect($anonymizedOrder->customerEmail)->toBeNull()
        ->and($anonymizedOrder->customerName)->toBeNull()
        ->and($anonymizedOrder->shippingAddress?->country)->toBe('RU')
        ->and($anonymizedOrder->shippingAddress?->region)->toBe('RU-MOW')
        ->and($anonymizedOrder->shippingAddress?->city)->toBeNull()
        ->and($anonymizedOrder->shippingAddress?->postalCode)->toBeNull()
        ->and($anonymizedOrder->shippingAddress?->lines)->toBe([])
        ->and($anonymizedOrder->shippingAddress?->recipient)->toBeNull()
        ->and($anonymizedOrder->shippingAddress?->phone)->toBeNull()
        ->and($anonymizedOrder->billingAddress?->country)->toBe('DE')
        ->and($anonymizedOrder->billingAddress?->region)->toBe('DE-BE')
        ->and($anonymizedOrder->billingAddress?->lines)->toBe([])
        ->and($anonymizedOrder->totals)->toEqual($order->totals)
        ->and($anonymizedOrder->amountDue)->toEqual($order->amountDue)
        ->and($anonymizedOrder->lines)->toHaveCount(1)
        ->and($anonymizedOrder->lines[0]->total)->toEqual($order->lines[0]->total);

    $anonymizedCart = slsCarts()->find($openCart->id);
    expect($anonymizedCart->shippingAddress?->country)->toBe('RU')
        ->and($anonymizedCart->shippingAddress?->region)->toBe('RU-MOW')
        ->and($anonymizedCart->shippingAddress?->lines)->toBe([])
        ->and($anonymizedCart->shippingAddress?->phone)->toBeNull();

    $untouched = slsOrders()->find($otherOrder->id);
    expect($untouched->customerEmail)->toBe('bob@example.com')
        ->and($untouched->customerName)->toBe('Bob Roe')
        ->and($untouched->shippingAddress?->lines)->toBe(['Tverskaya 1', 'Apt 12']);

    Event::assertDispatched(CustomerPersonalDataErased::class, static fn (CustomerPersonalDataErased $event): bool => $event->customer->equals($target)
        && in_array('order:'.$order->id->value, $event->bookingReferences, true));
});
