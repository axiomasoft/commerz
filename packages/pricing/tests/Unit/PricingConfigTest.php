<?php

declare(strict_types=1);

use Commerz\Money\RoundingMode;
use Commerz\Pricing\Configuration\PricingOptions;
use Packman\Config\Exceptions\InvalidConfiguration;

it('defaults to the base price fallback, the rates calculator and half-up tax rounding', function (): void {
    $config = PricingOptions::fromArray([]);

    expect($config->fallbackToSellable)->toBeTrue()
        ->and($config->taxCalculator)->toBe('rates')
        ->and($config->defaultTaxClass)->toBe('standard')
        ->and($config->deliveryTaxClass)->toBe('standard')
        ->and($config->defaultTaxZone)->toBeNull()
        ->and($config->taxRounding)->toBe(RoundingMode::HalfUp);
});

it('reads the shipped config file', function (): void {
    $config = PricingOptions::fromArray(require __DIR__.'/../../config/commerz-pricing.php');

    expect($config)->toEqual(PricingOptions::fromArray([]));
});

it('reads every key', function (): void {
    $config = PricingOptions::fromArray([
        'fallback_to_sellable' => false,
        'tax' => ['calculator' => 'acme.avalara', 'default_class' => 'general', 'delivery_class' => null, 'default_zone' => 'ru', 'rounding' => 'half_even'],
    ]);

    expect($config->fallbackToSellable)->toBeFalse()
        ->and($config->taxCalculator)->toBe('acme.avalara')
        ->and($config->defaultTaxClass)->toBe('general')
        ->and($config->deliveryTaxClass)->toBeNull()
        ->and($config->defaultTaxZone)->toBe('ru')
        ->and($config->taxRounding)->toBe(RoundingMode::HalfEven);
});

it('accepts a rounding mode enum', function (): void {
    expect(PricingOptions::fromArray(['tax' => ['rounding' => RoundingMode::Floor]])->taxRounding)->toBe(RoundingMode::Floor);
});

it('rejects unknown keys under closed schema validation', function (): void {
    expect(fn () => PricingOptions::fromArray(['unknown_key' => 1], 'app.pricing'))
        ->toThrow(InvalidConfiguration::class, 'app.pricing.unknown_key')
        ->and(fn () => PricingOptions::fromArray(['tax' => ['extra' => 1]], 'app.pricing'))
        ->toThrow(InvalidConfiguration::class, 'app.pricing.tax.extra');
});

it('names the offending key', function (array $config, string $path): void {
    expect(fn () => PricingOptions::fromArray($config))
        ->toThrow(InvalidConfiguration::class, $path);
})->with([
    'fallback not a bool' => [['fallback_to_sellable' => 1], 'commerz-pricing.fallback_to_sellable'],
    'tax not an array' => [['tax' => 'rates'], 'commerz-pricing.tax'],
    'calculator not a string' => [['tax' => ['calculator' => 1]], 'commerz-pricing.tax.calculator'],
    'calculator malformed' => [['tax' => ['calculator' => 'Rates One']], 'commerz-pricing.tax.calculator'],
    'rounding unknown' => [['tax' => ['rounding' => 'banker']], 'commerz-pricing.tax.rounding'],
    'rounding not a string' => [['tax' => ['rounding' => 3]], 'commerz-pricing.tax.rounding'],
    'default class null' => [['tax' => ['default_class' => null]], 'commerz-pricing.tax.default_class'],
    'default class malformed' => [['tax' => ['default_class' => 'Standard']], 'commerz-pricing.tax.default_class'],
    'delivery class malformed' => [['tax' => ['delivery_class' => 'a b']], 'commerz-pricing.tax.delivery_class'],
    'default zone malformed' => [['tax' => ['default_zone' => 7]], 'commerz-pricing.tax.default_zone'],
    'fallback null' => [['fallback_to_sellable' => null], 'commerz-pricing.fallback_to_sellable'],
    'tax null' => [['tax' => null], 'commerz-pricing.tax'],
    'calculator null' => [['tax' => ['calculator' => null]], 'commerz-pricing.tax.calculator'],
    'rounding null' => [['tax' => ['rounding' => null]], 'commerz-pricing.tax.rounding'],
]);
