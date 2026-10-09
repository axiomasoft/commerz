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
use Commerz\Shipping\Rates\Internal\WeightTableRate;
use Commerz\Stores\Data\ChannelId;

function wtStep(?int $upTo, string $amount, string $currency = 'RUB'): array
{
    return ['up_to_grams' => $upTo, 'amount' => Money::of($amount, $currency)];
}

/** @return array<string, mixed> */
function wtParams(bool $open = false): array
{
    return ['steps' => [wtStep(1000, '300.00'), wtStep(5000, '500.00'), ...($open ? [wtStep(null, '900.00')] : [])]];
}

function wtNormalize(array $input): RateParams
{
    return (new WeightTableRate)->normalize(RateParams::fromInput($input), new RateScope(Currency::of('RUB')));
}

/** @return array{int|null, string|null} the price in minor units, or null and the reason it was declined */
function wtPrice(array $input, int $grams): array
{
    $context = CommerceContext::for(ChannelId::generate(), Currency::of('RUB'), at: new DateTimeImmutable('2026-10-13T12:00:00Z'));
    $request = new DeliveryRequest($context, new Address('RU'), new Parcel($grams, Money::of('900.00', 'RUB')));
    $price = (new WeightTableRate)->price(new RateInput($request, wtNormalize($input), Currency::of('RUB'), 'russia'));

    return [$price->amount?->amountMinor, $price->reason];
}

/** The field of the InvalidRateConfig a call fails with, or null. */
function wtField(Closure $call): ?string
{
    try {
        $call();
    } catch (InvalidRateConfig $e) {
        return $e->field;
    }

    return null;
}

it('has the key weight_table at version 1', function (): void {
    expect((new WeightTableRate)->key())->toBe('weight_table')
        ->and((new WeightTableRate)->version())->toBe(1);
});

it('normalizes the steps to their canonical form, with or without an open step', function (): void {
    expect(wtNormalize(wtParams())->toArray())->toBe(['steps' => [
        ['amount' => ['amount_minor' => 30000, 'currency' => 'RUB'], 'up_to_grams' => 1000],
        ['amount' => ['amount_minor' => 50000, 'currency' => 'RUB'], 'up_to_grams' => 5000],
    ]])
        ->and(wtNormalize(wtParams(open: true))->toArray()['steps'][2])->toBe(['amount' => ['amount_minor' => 90000, 'currency' => 'RUB'], 'up_to_grams' => null])
        ->and(wtNormalize(['steps' => [wtStep(1, '0.00')]])->toArray()['steps'])->toHaveCount(1);
});

it('is stable: normalizing a normalized table changes nothing', function (): void {
    $once = wtNormalize(wtParams(open: true));

    expect((new WeightTableRate)->normalize($once, new RateScope(Currency::of('RUB'))))->toEqual($once);
});

it('refuses a table that is empty, too long, unordered, or has an open step that is not last', function (array $input, string $field): void {
    expect(wtField(fn () => wtNormalize($input)))->toBe($field);
})->with([
    'no parameters' => [[], 'steps'],
    'no steps' => [['steps' => []], 'steps'],
    'more than 100 steps' => [['steps' => array_map(static fn (int $i): array => wtStep($i + 1, '1.00'), range(1, 101))], 'steps'],
    'equal bounds' => [['steps' => [wtStep(1000, '1.00'), wtStep(1000, '2.00')]], 'steps.1.up_to_grams'],
    'descending bounds' => [['steps' => [wtStep(5000, '1.00'), wtStep(1000, '2.00')]], 'steps.1.up_to_grams'],
    'open step first' => [['steps' => [wtStep(null, '1.00'), wtStep(1000, '2.00')]], 'steps.0.up_to_grams'],
    'two open steps' => [['steps' => [wtStep(1000, '1.00'), wtStep(null, '1.00'), wtStep(null, '2.00')]], 'steps.1.up_to_grams'],
    'zero bound' => [['steps' => [wtStep(0, '1.00')]], 'steps.0.up_to_grams'],
    'bound over 10 000 000' => [['steps' => [wtStep(10_000_001, '1.00')]], 'steps.0.up_to_grams'],
    'bound is not an integer' => [['steps' => [['up_to_grams' => '1000', 'amount' => Money::of('1.00', 'RUB')]]], 'steps.0.up_to_grams'],
    'foreign currency' => [['steps' => [wtStep(1000, '1.00'), wtStep(2000, '1.00', 'USD')]], 'steps.1.amount'],
    'negative amount' => [['steps' => [['up_to_grams' => 1000, 'amount' => Money::ofMinor(-1, 'RUB')]]], 'steps.0.amount'],
    'missing amount' => [['steps' => [['up_to_grams' => 1000]]], 'steps.0'],
    'missing bound' => [['steps' => [['amount' => Money::of('1.00', 'RUB')]]], 'steps.0'],
    'extra key in a step' => [['steps' => [['up_to_grams' => 1000, 'amount' => Money::of('1.00', 'RUB'), 'note' => 'x']]], 'steps.0'],
    'a step that is not a map' => [['steps' => [1000]], 'steps.0'],
    'extra parameter' => [[...wtParams(), 'free_over' => 5], 'free_over'],
]);

it('accepts exactly 100 steps', function (): void {
    $steps = array_map(static fn (int $i): array => wtStep($i, '1.00'), range(1, 100));

    expect(wtNormalize(['steps' => $steps])->toArray()['steps'])->toHaveCount(100);
});

it('picks the first step the weight fits, the bound included', function (int $grams, int $minor): void {
    expect(wtPrice(wtParams(), $grams))->toBe([$minor, null]);
})->with([
    'no weight' => [0, 30000],
    'one gram' => [1, 30000],
    '999 g' => [999, 30000],
    '1000 g is the first bound' => [1000, 30000],
    '1001 g' => [1001, 50000],
    '5000 g is the last bound' => [5000, 50000],
]);

it('declines a parcel over the last finite bound instead of charging the last step', function (int $grams): void {
    expect(wtPrice(wtParams(), $grams))->toBe([null, 'weight_out_of_range']);
})->with([5001, 100_000]);

it('prices a parcel over the finite bounds at the open step', function (int $grams, int $minor): void {
    expect(wtPrice(wtParams(open: true), $grams))->toBe([$minor, null]);
})->with([
    'the last finite bound' => [5000, 50000],
    'one gram over' => [5001, 90000],
    'very heavy' => [9_999_999, 90000],
]);

it('has no older version to upgrade from', function (): void {
    expect(wtField(fn () => (new WeightTableRate)->upgrade(RateParams::fromInput([]), 1)))->toBe('version');
});
