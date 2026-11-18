<?php

declare(strict_types=1);

use Commerz\Context\CommerceContext;
use Commerz\Errors\CommerzException;
use Commerz\Errors\InvalidCommand;
use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Ports\Delivery\Data\Address;
use Commerz\Ports\Delivery\Data\DeliveryRequest;
use Commerz\Ports\Delivery\Data\Parcel;
use Commerz\Shipping\Rates\Data\RateInput;
use Commerz\Shipping\Rates\Data\RateParams;
use Commerz\Shipping\Rates\Data\RatePrice;
use Commerz\Shipping\Rates\Data\RateScope;
use Commerz\Shipping\Rates\Data\RateSpec;
use Commerz\Shipping\Rates\Exceptions\InvalidRateConfig;
use Commerz\Shipping\Rates\Exceptions\InvalidRateResult;
use Commerz\Shipping\Rates\Internal\LoadedRate;
use Commerz\Shipping\Tests\Fixtures\FixedRate;
use Commerz\Stores\Data\ChannelId;
use Packman\Errors\Enums\ErrorCategory;

/** The error code of the InvalidCommand a call fails with, or null. */
function rdtCode(Closure $call): ?string
{
    try {
        $call();
    } catch (InvalidCommand $e) {
        return $e->code();
    }

    return null;
}

it('accepts rate keys of the built-in and plugin shapes', function (string $key): void {
    expect((new RateSpec($key))->key)->toBe($key);
})->with(['flat', 'weight_table', 'acme.zone_matrix', 'a.b.c1']);

it('refuses a malformed rate key', function (string $key): void {
    expect(rdtCode(fn () => new RateSpec($key)))->toBe('shipping.invalid_rate');
})->with(['', 'Flat', '.x', 'x.', 'a b', '1abc', str_repeat('a', 65)]);

it('carries the tariff currency in the scope and the priced request in the input', function (): void {
    $context = CommerceContext::for(ChannelId::generate(), Currency::of('RUB'), at: new DateTimeImmutable('2026-10-13T12:00:00Z'));
    $request = new DeliveryRequest($context, new Address('RU'), new Parcel(1200, Money::of('900.00', 'RUB')));
    $input = new RateInput($request, RateParams::fromInput(['amount' => Money::of('300.00', 'RUB')]), Currency::of('RUB'), 'moscow');

    expect((new RateScope(Currency::of('RUB')))->currency->code)->toBe('RUB')
        ->and($input->request->parcel->weightGrams)->toBe(1200)
        ->and($input->zoneCode)->toBe('moscow')
        ->and((new FixedRate)->price($input)->amount?->equals(Money::of('300.00', 'RUB')))->toBeTrue();
});

it('prices a rate with an amount of zero or more or declines it with a snake_case reason', function (): void {
    $price = RatePrice::of(Money::of('300.00', 'RUB'));
    $free = RatePrice::of(Money::zero('RUB'));
    $declined = RatePrice::unavailable('weight_out_of_range', 'Over 5 kg.');

    expect($price->isAvailable())->toBeTrue()
        ->and($free->isAvailable())->toBeTrue()
        ->and($price->amount?->amountMinor)->toBe(30000)
        ->and([$price->reason, $price->message])->toBe([null, null])
        ->and($declined->isAvailable())->toBeFalse()
        ->and([$declined->amount, $declined->reason, $declined->message])->toBe([null, 'weight_out_of_range', 'Over 5 kg.'])
        ->and(rdtCode(fn () => RatePrice::of(Money::ofMinor(-1, 'RUB'))))->toBe('shipping.invalid_rate_price')
        ->and(rdtCode(fn () => RatePrice::unavailable('Too Heavy', 'x')))->toBe('shipping.invalid_rate_price')
        ->and(rdtCode(fn () => RatePrice::unavailable('', 'x')))->toBe('shipping.invalid_rate_price')
        ->and(rdtCode(fn () => RatePrice::unavailable('1st', 'x')))->toBe('shipping.invalid_rate_price');
});

it('is loaded with its rate and parameters or unsupported with a reason', function (): void {
    $params = RateParams::fromInput([]);
    $loaded = LoadedRate::loaded(new FixedRate, $params);
    $unsupported = LoadedRate::unsupported('rate_not_installed');

    expect($loaded->isSupported())->toBeTrue()
        ->and([$loaded->params, $loaded->reason])->toBe([$params, null])
        ->and($unsupported->isSupported())->toBeFalse()
        ->and([$unsupported->rate, $unsupported->params, $unsupported->reason])->toBe([null, null, 'rate_not_installed']);
});

it('classifies the rate errors', function (): void {
    $config = new InvalidRateConfig('amount', 'must not be negative');
    $result = new InvalidRateResult('acme.zone_matrix', 'negative price');

    expect($config)->toBeInstanceOf(CommerzException::class)
        ->and([$config->code(), $config->category(), $config->context()])->toBe(['shipping.invalid_rate_config', ErrorCategory::Validation, ['field' => 'amount', 'reason' => 'must not be negative']])
        ->and($config->getMessage())->toBe('Invalid rate parameter amount: must not be negative.')
        ->and([$result->code(), $result->category(), $result->context()])->toBe(['shipping.invalid_rate_result', ErrorCategory::Unavailable, ['rate' => 'acme.zone_matrix', 'reason' => 'negative price']]);
});
