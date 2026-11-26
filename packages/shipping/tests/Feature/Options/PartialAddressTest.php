<?php

declare(strict_types=1);

use Commerz\Money\Money;
use Commerz\Ports\Delivery\Data\Address;
use Commerz\Ports\Delivery\Data\DeliveryRequest;
use Commerz\Ports\Delivery\Data\Parcel;
use Commerz\Shipping\Facades\Shipping;
use Commerz\Shipping\Zones\Data\ZoneMember;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/Helpers.php';

uses(RefreshDatabase::class, OpEnvironment::class);

beforeEach(function (): void {
    $this->golden = opGolden();
    $this->web = $this->golden['channel'];
});

it('estimates delivery options from a partial address with only country and postal code', function (): void {
    $address = Address::partial('RU', postalCode: '101000');

    expect($address->isPartial())->toBeTrue();

    $request = new DeliveryRequest(
        opContext($this->web),
        $address,
        new Parcel(800, Money::of('3000.00', 'RUB')),
    );

    expect(opList($request))->toBe(['courier 200.00 msk-center -', 'pickup 0.00 ru -']);
});

it('matches a postal prefix when the partial address supplies only a prefix or formatted postal code', function (): void {
    $prefixRequest = new DeliveryRequest(
        opContext($this->web),
        Address::partial('RU', postalCode: '101'),
        new Parcel(800, Money::of('3000.00', 'RUB')),
    );

    expect(opList($prefixRequest))->toBe(['courier 200.00 msk-center -', 'pickup 0.00 ru -']);

    $ukZone = mtZone('uk-sw1a', [ZoneMember::postalPrefix('GB', 'SW1A')]);
    mtMethod('royal-mail', [
        'channels' => [$this->web],
        'rates' => [mtRate($ukZone->id, ['rate' => mtFlat('150.00')])],
    ]);

    $applePayAddress = Address::partial('GB', city: 'London', postalCode: 'SW1A 1AA');
    $applePayRequest = new DeliveryRequest(
        opContext($this->web),
        $applePayAddress,
        new Parcel(500, Money::of('1000.00', 'RUB')),
    );

    expect($applePayAddress->isPartial())->toBeTrue()
        ->and(Shipping::options()->list($applePayRequest))->toHaveCount(1)
        ->and(opList($applePayRequest))->toBe(['royal-mail 150.00 uk-sw1a -']);
});

it('falls back to the country zone for a country-only partial address', function (): void {
    $request = new DeliveryRequest(
        opContext($this->web),
        Address::partial('RU'),
        new Parcel(800, Money::of('3000.00', 'RUB')),
    );

    expect(opList($request))->toBe(['courier 300.00 ru 3', 'pickup 0.00 ru -']);
});
