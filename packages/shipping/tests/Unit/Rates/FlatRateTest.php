<?php

declare(strict_types=1);

use Commerz\Context\CommerceContext;
use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Ports\Delivery\Data\Address;
use Commerz\Ports\Delivery\Data\DeliveryRequest;
use Commerz\Ports\Delivery\Data\Parcel;
use Commerz\Shipping\Rates\Data\RateInput;
use Commerz\Shipping\Rates\Data\RateParams;
use Commerz\Shipping\Rates\Data\RateScope;
use Commerz\Shipping\Rates\Exceptions\InvalidRateConfig;
use Commerz\Shipping\Rates\Internal\FlatRate;
use Commerz\Stores\Data\ChannelId;

/** @param  array<string, mixed>  $params */
function frInput(array $params, int $grams = 1500, string $currency = 'RUB'): RateInput
{
    $context = CommerceContext::for(ChannelId::generate(), Currency::of($currency), at: new DateTimeImmutable('2026-10-13T12:00:00Z'));
    $request = new DeliveryRequest($context, new Address('RU'), new Parcel($grams, Money::of('900.00', $currency)));

    return new RateInput($request, RateParams::fromInput($params), Currency::of($currency), 'russia');
}

/** The field of the InvalidRateConfig a call fails with, or null. */
function frField(Closure $call): ?string
{
    try {
        $call();
    } catch (InvalidRateConfig $e) {
        return $e->field;
    }

    return null;
}

it('has the key flat at version 1', function (): void {
    expect((new FlatRate)->key())->toBe('flat')
        ->and((new FlatRate)->version())->toBe(1);
});

it('normalizes the amount to its canonical form, free delivery included', function (): void {
    $scope = new RateScope(Currency::of('RUB'));

    expect((new FlatRate)->normalize(RateParams::fromInput(['amount' => Money::of('300.00', 'RUB')]), $scope)->toArray())
        ->toBe(['amount' => ['amount_minor' => 30000, 'currency' => 'RUB']])
        ->and((new FlatRate)->normalize(RateParams::fromInput(['amount' => Money::zero('RUB')]), $scope)->toArray())
        ->toBe(['amount' => ['amount_minor' => 0, 'currency' => 'RUB']]);
});

it('refuses a missing, foreign, negative or malformed amount and any other parameter', function (array $input, string $field): void {
    expect(frField(fn () => (new FlatRate)->normalize(RateParams::fromInput($input), new RateScope(Currency::of('RUB')))))->toBe($field);
})->with([
    'nothing' => [[], 'amount'],
    'foreign currency' => [['amount' => Money::of('1.00', 'USD')], 'amount'],
    'negative' => [['amount' => Money::ofMinor(-1, 'RUB')], 'amount'],
    'not an amount' => [['amount' => 300], 'amount'],
    'extra parameter' => [['amount' => Money::of('1.00', 'RUB'), 'free_over' => 5], 'free_over'],
]);

it('prices every parcel at the amount, whatever its weight', function (int $grams): void {
    $price = (new FlatRate)->price(frInput(['amount' => Money::of('300.00', 'RUB')], $grams));

    expect($price->isAvailable())->toBeTrue()
        ->and($price->amount?->equals(Money::of('300.00', 'RUB')))->toBeTrue();
})->with([0, 1, 1500, 10_000_000]);

it('prices zero for free delivery', function (): void {
    expect((new FlatRate)->price(frInput(['amount' => Money::zero('RUB')]))->amount?->isZero())->toBeTrue();
});

it('has no older version to upgrade from', function (): void {
    expect(frField(fn () => (new FlatRate)->upgrade(RateParams::fromInput([]), 1)))->toBe('version');
});
