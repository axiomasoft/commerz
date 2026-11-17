<?php

declare(strict_types=1);

use Commerz\Money\RoundingMode;
use Commerz\Ports\Promotions\Enums\RestoreReason;
use Commerz\Promotions\Configuration\PromotionsOptions;
use Packman\Config\Exceptions\InvalidConfiguration;

it('defaults to half-up rounding, five codes, restore on cancel and expiry, and a readable alphabet', function (): void {
    $config = PromotionsOptions::fromArray([]);

    expect($config->rounding)->toBe(RoundingMode::HalfUp)
        ->and($config->maxCodes)->toBe(5)
        ->and($config->restoreOn)->toBe([RestoreReason::Cancelled, RestoreReason::Expired])
        ->and($config->couponAlphabet)->toBe('23456789ABCDEFGHJKMNPQRSTUVWXYZ')
        ->and($config->maxGenerate)->toBe(50000)
        ->and($config->restores(RestoreReason::Cancelled))->toBeTrue()
        ->and($config->restores(RestoreReason::Refunded))->toBeFalse();
});

it('reads the shipped config file', function (): void {
    expect(PromotionsOptions::fromArray(require __DIR__.'/../../config/commerz-promotions.php'))->toEqual(PromotionsOptions::fromArray([]));
});

it('reads every key', function (): void {
    $config = PromotionsOptions::fromArray([
        'rounding' => 'half_even',
        'max_codes' => 2,
        'restore_on' => ['refunded', RestoreReason::Refunded, 'cancelled'],
        'coupons' => ['alphabet' => 'AB01', 'max_generate' => 10],
    ]);

    expect($config->rounding)->toBe(RoundingMode::HalfEven)
        ->and($config->maxCodes)->toBe(2)
        ->and($config->restoreOn)->toBe([RestoreReason::Refunded, RestoreReason::Cancelled])
        ->and($config->couponAlphabet)->toBe('AB01')
        ->and($config->maxGenerate)->toBe(10);
});

it('accepts a rounding mode enum and an empty restore list', function (): void {
    $config = PromotionsOptions::fromArray(['rounding' => RoundingMode::Floor, 'restore_on' => []]);

    expect($config->rounding)->toBe(RoundingMode::Floor)->and($config->restoreOn)->toBe([]);
});

it('rejects unknown keys under closed schema validation', function (): void {
    expect(fn () => PromotionsOptions::fromArray(['unknown_key' => 1], 'app.promotions'))
        ->toThrow(InvalidConfiguration::class, 'app.promotions.unknown_key')
        ->and(fn () => PromotionsOptions::fromArray(['coupons' => ['extra' => 1]], 'app.promotions'))
        ->toThrow(InvalidConfiguration::class, 'app.promotions.coupons.extra');
});

it('names the offending key', function (array $config, string $path): void {
    expect(fn () => PromotionsOptions::fromArray($config))
        ->toThrow(InvalidConfiguration::class, $path);
})->with([
    'rounding unknown' => [['rounding' => 'banker'], 'commerz-promotions.rounding'],
    'rounding not a string' => [['rounding' => 3], 'commerz-promotions.rounding'],
    'max codes zero' => [['max_codes' => 0], 'commerz-promotions.max_codes'],
    'max codes too many' => [['max_codes' => 51], 'commerz-promotions.max_codes'],
    'max codes not an int' => [['max_codes' => '5'], 'commerz-promotions.max_codes'],
    'restore on not a list' => [['restore_on' => 'cancelled'], 'commerz-promotions.restore_on'],
    'restore on keyed' => [['restore_on' => ['a' => 'cancelled']], 'commerz-promotions.restore_on'],
    'restore on unknown reason' => [['restore_on' => ['lost']], 'commerz-promotions.restore_on'],
    'coupons not an array' => [['coupons' => 'x'], 'commerz-promotions.coupons'],
    'alphabet lower case' => [['coupons' => ['alphabet' => 'abc']], 'commerz-promotions.coupons.alphabet'],
    'alphabet one character' => [['coupons' => ['alphabet' => 'A']], 'commerz-promotions.coupons.alphabet'],
    'alphabet repeats' => [['coupons' => ['alphabet' => 'AAB']], 'commerz-promotions.coupons.alphabet'],
    'alphabet not a string' => [['coupons' => ['alphabet' => ['A', 'B']]], 'commerz-promotions.coupons.alphabet'],
    'max generate zero' => [['coupons' => ['max_generate' => 0]], 'commerz-promotions.coupons.max_generate'],
    'max generate too large' => [['coupons' => ['max_generate' => 1_000_001]], 'commerz-promotions.coupons.max_generate'],
]);
