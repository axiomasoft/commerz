<?php

declare(strict_types=1);

use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Shipping\Rates\Data\RateParams;
use Commerz\Shipping\Rates\Exceptions\InvalidRateConfig;

enum RatePurpose: string
{
    case Courier = 'courier';
}

/**
 * The field of the InvalidRateConfig a call fails with, or null.
 *
 * @param  Closure(): mixed  $call
 */
function rpmField(Closure $call): ?string
{
    try {
        $call();
    } catch (InvalidRateConfig $e) {
        return $e->field;
    }

    return null;
}

it('turns Money, enums and dates into plain values and sorts the keys', function (): void {
    $params = RateParams::fromInput([
        'z' => RatePurpose::Courier,
        'amount' => Money::of('12.50', 'RUB'),
        'until' => new DateTimeImmutable('2026-10-13T15:00:00+03:00'),
        'list' => [3, 1, 2],
        'nested' => ['b' => null, 'a' => true],
    ]);

    expect($params->toArray())->toBe([
        'amount' => ['amount_minor' => 1250, 'currency' => 'RUB'],
        'list' => [3, 1, 2],
        'nested' => ['a' => true, 'b' => null],
        'until' => '2026-10-13T12:00:00Z',
        'z' => 'courier',
    ]);
});

it('gives the same canonical form whatever the order of the keys and survives a trip through JSON', function (): void {
    $params = RateParams::fromInput(['steps' => [['up_to_grams' => 1000, 'amount' => Money::of('3.00', 'RUB')]], 'a' => 1]);

    $decoded = json_decode(json_encode($params->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    if (! is_array($decoded)) {
        throw new RuntimeException('canonical params must decode to an array');
    }

    expect(RateParams::fromInput(['a' => 1, 'b' => 2]))->toEqual(RateParams::fromInput(['b' => 2, 'a' => 1]))
        ->and(json_encode(RateParams::fromInput(['b' => ['y' => 1, 'x' => 2], 'a' => 1])->toArray()))->toBe('{"a":1,"b":{"x":2,"y":1}}')
        ->and(RateParams::fromInput([])->toArray())->toBe([])
        ->and(RateParams::fromStored($decoded))->toEqual($params);
});

it('refuses what cannot be stored', function (array $input, string $field): void {
    expect(rpmField(fn () => RateParams::fromInput($input)))->toBe($field)
        ->and(rpmField(fn () => RateParams::fromStored($input)))->toBe($field);
})->with([
    'a float' => [['rate' => 0.1], 'rate'],
    'a nested float' => [['steps' => [['weight' => 0.5]]], 'steps.0.weight'],
    'an object' => [['rate' => new stdClass], 'rate'],
    'a closure' => [['rate' => fn () => 1], 'rate'],
    'a list at the top' => [[1, 2], 'params'],
    'a non-string key' => [['a' => [1 => 'x', 'y' => 'z']], 'a'],
]);

it('refuses parameters nested too deep', function (): void {
    $deep = ['x' => 1];

    foreach (range(1, 9) as $_) {
        $deep = ['x' => $deep];
    }

    expect(rpmField(fn () => RateParams::fromInput($deep)))->not->toBeNull();
});

it('reads integers within bounds', function (): void {
    $params = RateParams::fromInput(['days' => 5, 'text' => '5', 'none' => null]);

    expect($params->int('days', 1, 10))->toBe(5)
        ->and($params->nullableInt('days', 1, 10))->toBe(5)
        ->and($params->nullableInt('none'))->toBeNull()
        ->and($params->nullableInt('missing'))->toBeNull()
        ->and(rpmField(fn () => $params->int('days', 6, 10)))->toBe('days')
        ->and(rpmField(fn () => $params->int('days', 1, 4)))->toBe('days')
        ->and(rpmField(fn () => $params->int('text')))->toBe('text')
        ->and(rpmField(fn () => $params->int('missing')))->toBe('missing')
        ->and(rpmField(fn () => $params->int('none')))->toBe('none')
        ->and(rpmField(fn () => $params->nullableInt('text')))->toBe('text');
});

it('reads money in the tariff currency only', function (): void {
    $rub = Currency::of('RUB');
    $params = RateParams::fromInput(['amount' => Money::of('300.00', 'RUB'), 'other' => Money::of('1.00', 'USD'), 'broken' => ['amount_minor' => 1], 'text' => '5']);

    expect($params->money('amount', $rub)->equals(Money::of('300.00', 'RUB')))->toBeTrue()
        ->and(rpmField(fn () => $params->money('other', $rub)))->toBe('other')
        ->and(rpmField(fn () => $params->money('broken', $rub)))->toBe('broken')
        ->and(rpmField(fn () => $params->money('text', $rub)))->toBe('text')
        ->and(rpmField(fn () => $params->money('missing', $rub)))->toBe('missing');

    try {
        $params->money('other', $rub);
    } catch (InvalidRateConfig $e) {
        expect($e->reason)->toBe('must be in RUB');
    }
});

it('reads a bounded list of maps', function (): void {
    $params = RateParams::fromInput([
        'steps' => [['up_to_grams' => 1, 'amount' => Money::of('1.00', 'RUB')], ['up_to_grams' => null, 'amount' => Money::of('2.00', 'RUB')]],
        'scalars' => [1, 2],
        'mapping' => ['a' => 1],
        'mixed' => [['a' => 1], 5],
        'empty' => [],
    ]);

    expect($params->list('steps', 1, 2))->toHaveCount(2)
        ->and($params->list('steps', 1, 2)[1])->toBe(['amount' => ['amount_minor' => 200, 'currency' => 'RUB'], 'up_to_grams' => null])
        ->and($params->list('empty', 0, 3))->toBe([])
        ->and(rpmField(fn () => $params->list('steps', 3, 4)))->toBe('steps')
        ->and(rpmField(fn () => $params->list('steps', 1, 1)))->toBe('steps')
        ->and(rpmField(fn () => $params->list('scalars', 1, 5)))->toBe('scalars.0')
        ->and(rpmField(fn () => $params->list('mapping', 1, 5)))->toBe('mapping')
        ->and(rpmField(fn () => $params->list('mixed', 1, 5)))->toBe('mixed.1')
        ->and(rpmField(fn () => $params->list('missing', 0, 5)))->toBe('missing');
});

it('refuses a parameter outside the allowed list', function (): void {
    $params = RateParams::fromInput(['amount' => 1, 'typo' => 2]);

    expect($params->only(['amount', 'typo']))->toBe($params)
        ->and(rpmField(fn () => $params->only(['amount'])))->toBe('typo');
});
