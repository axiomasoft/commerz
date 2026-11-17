<?php

declare(strict_types=1);

use Commerz\Errors\InvalidCommand;
use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Shipping\Rates\Contracts\DeliveryRate;
use Commerz\Shipping\Rates\Data\RateParams;
use Commerz\Shipping\Rates\Data\RatePrice;
use Commerz\Shipping\Rates\Data\RateScope;
use Commerz\Shipping\Rates\Data\RateSpec;
use Commerz\Shipping\Rates\Exceptions\InvalidRateResult;
use Commerz\Shipping\Rates\Internal\RateCodec;
use Commerz\Shipping\Rates\Internal\StoredRate;
use Commerz\Shipping\Tests\Fixtures\BrokenLoadingRate;
use Commerz\Shipping\Tests\Fixtures\FixturePlugin;
use Packman\Plugins\Contracts\Contributions\Contributions;

beforeEach(function (): void {
    config(['commerz.plugins' => [FixturePlugin::class]]);
});

function rtCodec(): RateCodec
{
    return app(RateCodec::class);
}

function rtScope(string $currency = 'RUB'): RateScope
{
    return new RateScope(Currency::of($currency));
}

/** The error code and field of the InvalidCommand a call fails with. */
function rtFailure(Closure $call): string
{
    try {
        $call();
    } catch (InvalidCommand $e) {
        return "{$e->code()} @ {$e->field}";
    }

    return 'no error';
}

it('encodes a rate with the key, the plugin version and canonical parameters', function (): void {
    $stored = rtCodec()->encode(new RateSpec('test.fixed', ['amount' => Money::of('300.00', 'RUB')]), rtScope(), 'rates.0.rate');

    expect($stored)->toBeInstanceOf(StoredRate::class)
        ->and($stored->key)->toBe('test.fixed')
        ->and($stored->version)->toBe(2)
        ->and($stored->params->toArray())->toBe(['amount' => ['amount_minor' => 30000, 'currency' => 'RUB']]);
});

it('refuses an unknown key, naming the field', function (string $key): void {
    expect(rtFailure(fn () => rtCodec()->encode(new RateSpec($key), rtScope(), 'rates.1.rate')))->toBe('shipping.unknown_rate @ rates.1.rate.key');
})->with(['acme.missing', 'carrier_like']);

it('reports invalid parameters with the path of the field', function (): void {
    $encode = static fn (array $params): Closure => static fn () => rtCodec()->encode(new RateSpec('test.fixed', $params), rtScope(), 'rates.0.rate');

    expect(rtFailure($encode(['amount' => Money::of('1.00', 'USD')])))->toBe('shipping.invalid_rate_config @ rates.0.rate.params.amount')
        ->and(rtFailure($encode([])))->toBe('shipping.invalid_rate_config @ rates.0.rate.params.amount')
        ->and(rtFailure($encode(['amount' => Money::of('1.00', 'RUB'), 'extra' => 1])))->toBe('shipping.invalid_rate_config @ rates.0.rate.params.extra')
        ->and(rtFailure($encode(['amount' => Money::ofMinor(-1, 'RUB')])))->toBe('shipping.invalid_rate_config @ rates.0.rate.params.amount')
        ->and(rtFailure($encode(['amount' => 1.5])))->toBe('shipping.invalid_rate_config @ rates.0.rate.params.amount');
});

it('validates a rate stored at the current version', function (): void {
    $stored = new StoredRate('test.fixed', 2, RateParams::fromInput(['amount' => Money::of('5.00', 'RUB')]));
    $loaded = rtCodec()->decode($stored, rtScope());

    expect($loaded->isSupported())->toBeTrue()
        ->and($loaded->rate)->toBeInstanceOf(DeliveryRate::class)
        ->and($loaded->params?->toArray())->toBe(['amount' => ['amount_minor' => 500, 'currency' => 'RUB']])
        ->and($loaded->reason)->toBeNull();
});

it('upgrades the parameters of an older version', function (): void {
    $stored = new StoredRate('test.fixed', 1, RateParams::fromInput(['cost' => ['amount_minor' => 700, 'currency' => 'RUB']]));
    $loaded = rtCodec()->decode($stored, rtScope());

    expect($loaded->isSupported())->toBeTrue()
        ->and($loaded->params?->toArray())->toBe(['amount' => ['amount_minor' => 700, 'currency' => 'RUB']]);
});

it('does not throw on stored data: a missing plugin, a newer version, a failed upgrade or bad parameters is unsupported', function (string $key, int $version, array $params, string $reason): void {
    $loaded = rtCodec()->decode(new StoredRate($key, $version, RateParams::fromInput($params)), rtScope());

    expect($loaded->isSupported())->toBeFalse()
        ->and($loaded->rate)->toBeNull()
        ->and($loaded->params)->toBeNull()
        ->and($loaded->reason)->toContain($reason);
})->with([
    'no plugin contributes the key' => ['acme.gone', 1, [], "no rate contributes 'acme.gone'"],
    'stored by a newer plugin' => ['test.fixed', 3, ['amount' => ['amount_minor' => 1, 'currency' => 'RUB']], 'stored at version 3, the plugin knows 2'],
    'the upgrade fails on the old shape' => ['test.newer', 1, [], 'the old format is gone'],
    'the upgrade of the old shape yields nothing valid' => ['test.fixed', 1, ['other' => 1], 'stored parameters are invalid'],
    'normalize fails at the current version' => ['test.fixed', 2, ['amount' => ['amount_minor' => -5, 'currency' => 'RUB']], 'must not be negative'],
    'unknown parameter at the current version' => ['test.fixed', 2, ['amount' => ['amount_minor' => 5, 'currency' => 'RUB'], 'extra' => 1], 'unknown parameter'],
    'the tariff currency changed' => ['test.fixed', 2, ['amount' => ['amount_minor' => 5, 'currency' => 'USD']], 'must be in RUB'],
]);

it('treats malformed stored parameters of a row as unsupported', function (): void {
    $loaded = rtCodec()->decodeRow('test.fixed', 2, ['amount' => 1.5], rtScope());

    expect($loaded->isSupported())->toBeFalse()
        ->and($loaded->reason)->toContain('stored parameters are invalid')
        ->and(rtCodec()->decodeRow('test.fixed', 2, ['amount' => ['amount_minor' => 9, 'currency' => 'RUB']], rtScope())->isSupported())->toBeTrue();
});

it('refuses a price in a currency other than the tariff\'s', function (): void {
    $codec = rtCodec();
    $rub = RatePrice::of(Money::of('1.00', 'RUB'));

    expect($codec->assertPrice('test.fixed', $rub, Currency::of('RUB')))->toBe($rub)
        ->and($codec->assertPrice('test.fixed', RatePrice::unavailable('too_heavy', 'No.'), Currency::of('RUB'))->isAvailable())->toBeFalse()
        ->and(fn () => $codec->assertPrice('test.fixed', RatePrice::of(Money::of('1.00', 'USD')), Currency::of('RUB')))
        ->toThrow(InvalidRateResult::class, 'the price is in USD, the tariff is in RUB');
});

it('contains unexpected plugin failures while loading a stored tariff', function (string $operation, int $version): void {
    $rate = app(Contributions::class)->get(DeliveryRate::KIND, 'test.broken_loading', DeliveryRate::class);
    expect($rate)->toBeInstanceOf(BrokenLoadingRate::class);
    $rate->failOn = $operation;

    $loaded = rtCodec()->decode(new StoredRate($rate->key(), $version, RateParams::fromInput([])), rtScope());

    expect($loaded->isSupported())->toBeFalse()
        ->and($loaded->reason)->toContain("{$operation} failed");
})->with([
    ['version', 2],
    ['normalize', 2],
    ['upgrade', 1],
]);
