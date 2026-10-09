<?php

declare(strict_types=1);

use Commerz\Errors\InvalidConfiguration;
use Commerz\Shipping\Common\Internal\ShippingConfig;

it('defaults to prices without tax, 2000 zone members and a 120 second booking lease', function (): void {
    $config = ShippingConfig::fromArray([]);

    expect($config->taxIncluded)->toBeFalse()
        ->and($config->maxZoneMembers)->toBe(2000)
        ->and($config->bookingLeaseSeconds)->toBe(120);
});

it('reads the shipped config file', function (): void {
    expect(ShippingConfig::fromArray(require __DIR__.'/../../config/commerz-shipping.php'))->toEqual(ShippingConfig::fromArray([]));
});

it('reads every key', function (): void {
    $config = ShippingConfig::fromArray(['tax_included' => true, 'max_zone_members' => 10, 'booking' => ['lease_seconds' => 30]]);

    expect($config->taxIncluded)->toBeTrue()
        ->and($config->maxZoneMembers)->toBe(10)
        ->and($config->bookingLeaseSeconds)->toBe(30);
});

it('names the key of a wrong value', function (array $config, string $key): void {
    expect(fn () => ShippingConfig::fromArray($config))
        ->toThrow(fn (InvalidConfiguration $e) => expect($e->getMessage())->toContain($key));
})->with([
    'tax_included not a bool' => [['tax_included' => 'yes'], 'commerz-shipping.tax_included'],
    'no zone members allowed' => [['max_zone_members' => 0], 'commerz-shipping.max_zone_members'],
    'too many zone members' => [['max_zone_members' => 100_001], 'commerz-shipping.max_zone_members'],
    'zone members not an int' => [['max_zone_members' => '5'], 'commerz-shipping.max_zone_members'],
    'booking not an array' => [['booking' => 120], 'commerz-shipping.booking'],
    'lease too short' => [['booking' => ['lease_seconds' => 9]], 'commerz-shipping.booking.lease_seconds'],
    'lease too long' => [['booking' => ['lease_seconds' => 3601]], 'commerz-shipping.booking.lease_seconds'],
]);

it('accepts the bounds', function (): void {
    expect(ShippingConfig::fromArray(['max_zone_members' => 1, 'booking' => ['lease_seconds' => 10]])->maxZoneMembers)->toBe(1)
        ->and(ShippingConfig::fromArray(['max_zone_members' => 100_000, 'booking' => ['lease_seconds' => 3600]])->bookingLeaseSeconds)->toBe(3600);
});
