<?php

declare(strict_types=1);

use Commerz\Shipping\Facades\Shipping as ShippingFacade;
use Commerz\Shipping\Methods\Contracts\Methods;
use Commerz\Shipping\Packing\Contracts\ParcelPacker;
use Commerz\Shipping\PickupPoints\Contracts\PickupPoints;
use Commerz\Shipping\Shipping;
use Commerz\Shipping\Zones\Contracts\Zones;

it('hands out the module contracts from the root', function (): void {
    $root = app(Shipping::class);

    expect($root->zones())->toBe(app(Zones::class))
        ->and($root->methods())->toBe(app(Methods::class))
        ->and($root->pickupPoints())->toBe(app(PickupPoints::class))
        ->and($root->packer())->toBe(app(ParcelPacker::class))
        ->and(app(Shipping::class))->toBe($root);
});

it('reaches the same modules through the facade', function (): void {
    expect(ShippingFacade::zones())->toBe(app(Zones::class))
        ->and(ShippingFacade::methods())->toBe(app(Methods::class))
        ->and(ShippingFacade::pickupPoints())->toBe(app(PickupPoints::class))
        ->and(ShippingFacade::packer())->toBe(app(ParcelPacker::class));
});
