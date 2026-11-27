<?php

declare(strict_types=1);

use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Promotions\Rules\Data\RuleParams;
use Commerz\Promotions\Rules\Enums\PromotionStage;
use Commerz\Promotions\Rules\Exceptions\InvalidRuleConfig;

/**
 * The field of the InvalidRuleConfig a call fails with, or null.
 *
 * @param  Closure(): mixed  $call
 */
function rpField(Closure $call): ?string
{
    try {
        $call();
    } catch (InvalidRuleConfig $e) {
        return $e->field;
    }

    return null;
}

it('turns Money, enums and dates into plain values and sorts the keys', function (): void {
    $params = RuleParams::fromInput([
        'z' => PromotionStage::Cart,
        'amount' => Money::of('12.50', 'RUB'),
        'until' => new DateTimeImmutable('2026-10-12T15:00:00+03:00'),
        'list' => [3, 1, 2],
        'nested' => ['b' => null, 'a' => true],
    ]);

    expect($params->toArray())->toBe([
        'amount' => ['amount_minor' => 1250, 'currency' => 'RUB'],
        'list' => [3, 1, 2],
        'nested' => ['a' => true, 'b' => null],
        'until' => '2026-10-12T12:00:00Z',
        'z' => 'cart',
    ]);
});

it('gives the same canonical form whatever the order of the keys', function (): void {
    expect(RuleParams::fromInput(['a' => 1, 'b' => 2]))->toEqual(RuleParams::fromInput(['b' => 2, 'a' => 1]))
        ->and(json_encode(RuleParams::fromInput(['b' => ['y' => 1, 'x' => 2], 'a' => 1])->toArray()))->toBe('{"a":1,"b":{"x":2,"y":1}}');
});

it('accepts an empty map and survives a trip through JSON', function (): void {
    $params = RuleParams::fromInput(['amount' => Money::of('5.00', 'RUB'), 'groups' => ['vip'], 'min' => 2]);

    $decoded = json_decode(json_encode($params->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    if (! is_array($decoded)) {
        throw new RuntimeException('Expected rule parameters to survive JSON.');
    }

    expect(RuleParams::fromInput([])->toArray())->toBe([])
        ->and(RuleParams::fromInput($decoded))->toEqual($params);
});

it('refuses what cannot be stored', function (array $input, string $field): void {
    expect(rpField(fn () => RuleParams::fromInput($input)))->toBe($field);
})->with([
    'a float' => [['rate' => 0.1], 'rate'],
    'a nested float' => [['tiers' => [['rate' => 0.1]]], 'tiers.rate'],
    'an object' => [['rule' => new stdClass], 'rule'],
    'a closure' => [['rule' => fn () => 1], 'rule'],
    'a resource' => [['file' => fopen('php://memory', 'r')], 'file'],
    'a list at the top' => [[1, 2], 'params'],
    'integer keys in a map' => [['a' => [1 => 'x', 3 => 'y']], 'a'],
    'too deep' => [['a' => ['b' => ['c' => ['d' => ['e' => ['f' => ['g' => ['h' => ['i' => 1]]]]]]]]], 'a.b.c.d.e.f.g.h'],
]);

it('reads a required integer within its range', function (): void {
    $params = RuleParams::fromInput(['n' => 5, 's' => 'x']);

    expect($params->int('n'))->toBe(5)
        ->and($params->int('n', 1, 5))->toBe(5)
        ->and(rpField(fn () => $params->int('n', 6, 9)))->toBe('n')
        ->and(rpField(fn () => $params->int('n', 1, 4)))->toBe('n')
        ->and(rpField(fn () => $params->int('s')))->toBe('s')
        ->and(rpField(fn () => $params->int('missing')))->toBe('missing');
});

it('reads an optional integer', function (): void {
    $params = RuleParams::fromInput(['n' => 5, 'none' => null, 'bad' => '5']);

    expect($params->nullableInt('n', 1, 9))->toBe(5)
        ->and($params->nullableInt('none'))->toBeNull()
        ->and($params->nullableInt('missing'))->toBeNull()
        ->and(rpField(fn () => $params->nullableInt('bad')))->toBe('bad')
        ->and(rpField(fn () => $params->nullableInt('n', 6)))->toBe('n');
});

it('reads a string against a pattern', function (): void {
    $params = RuleParams::fromInput(['code' => 'vip', 'n' => 1]);

    expect($params->string('code'))->toBe('vip')
        ->and($params->string('code', '/\A[a-z]+\z/'))->toBe('vip')
        ->and(rpField(fn () => $params->string('code', '/\A\d+\z/')))->toBe('code')
        ->and(rpField(fn () => $params->string('n')))->toBe('n')
        ->and(rpField(fn () => $params->string('missing')))->toBe('missing');
});

it('reads a list of strings with a size and a pattern', function (): void {
    $params = RuleParams::fromInput(['groups' => ['vip', 'gold'], 'map' => ['a' => 'x'], 'mixed' => ['a', 1]]);

    expect($params->stringList('groups', 1, 3))->toBe(['vip', 'gold'])
        ->and(rpField(fn () => $params->stringList('groups', 3, 4)))->toBe('groups')
        ->and(rpField(fn () => $params->stringList('groups', 0, 1)))->toBe('groups')
        ->and(rpField(fn () => $params->stringList('groups', 1, 3, '/\Av/')))->toBe('groups')
        ->and(rpField(fn () => $params->stringList('map', 0, 3)))->toBe('map')
        ->and(rpField(fn () => $params->stringList('mixed', 0, 3)))->toBe('mixed')
        ->and(rpField(fn () => $params->stringList('missing', 0, 3)))->toBe('missing');
});

it('reads an amount in the promotion currency', function (): void {
    $rub = Currency::of('RUB');
    $params = RuleParams::fromInput(['min' => Money::of('100.00', 'RUB'), 'usd' => Money::of('1.00', 'USD'), 'plain' => 100, 'extra' => ['amount_minor' => 1, 'currency' => 'RUB', 'x' => 1]]);

    expect($params->money('min', $rub)->equals(Money::of('100.00', 'RUB')))->toBeTrue()
        ->and(rpField(fn () => $params->money('usd', $rub)))->toBe('usd')
        ->and(rpField(fn () => $params->money('plain', $rub)))->toBe('plain')
        ->and(rpField(fn () => $params->money('extra', $rub)))->toBe('extra')
        ->and(rpField(fn () => $params->money('missing', $rub)))->toBe('missing');
});

it('reads one of the allowed choices, with a default', function (): void {
    $params = RuleParams::fromInput(['mode' => 'any', 'n' => 1]);

    expect($params->choice('mode', ['any', 'all']))->toBe('any')
        ->and($params->choice('absent', ['any', 'all'], 'all'))->toBe('all')
        ->and(rpField(fn () => $params->choice('mode', ['all'])))->toBe('mode')
        ->and(rpField(fn () => $params->choice('absent', ['any', 'all'])))->toBe('absent')
        ->and(rpField(fn () => $params->choice('n', ['any', 'all'])))->toBe('n');
});

it('refuses a parameter that is not expected', function (): void {
    $params = RuleParams::fromInput(['min' => 1, 'mni' => 2]);

    expect($params->only(['min', 'mni']))->toBe($params)
        ->and(rpField(fn () => $params->only(['min'])))->toBe('mni');
});

it('lists the currencies of all the amounts inside, sorted', function (): void {
    expect(RuleParams::fromInput([])->currencies())->toBe([])
        ->and(RuleParams::fromInput(['n' => 1])->currencies())->toBe([])
        ->and(RuleParams::fromInput([
            'a' => Money::of('1.00', 'USD'),
            'tiers' => [['from' => Money::of('1.00', 'RUB')], ['from' => Money::of('2.00', 'RUB')]],
        ])->currencies())->toBe(['RUB', 'USD']);
});
