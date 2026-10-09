<?php

declare(strict_types=1);

use Commerz\Errors\InvalidCommand;
use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Promotions\Rules\Contracts\PromotionCondition;
use Commerz\Promotions\Rules\Data\RuleParams;
use Commerz\Promotions\Rules\Data\RuleScope;
use Commerz\Promotions\Rules\Data\RuleSpec;
use Commerz\Promotions\Rules\Enums\PromotionStage;
use Commerz\Promotions\Rules\Internal\RuleCodec;
use Commerz\Promotions\Rules\Internal\StoredRule;
use Commerz\Promotions\Tests\Fixtures\FixturePlugin;

beforeEach(function (): void {
    config(['commerz.plugins' => [FixturePlugin::class]]);
});

function rcCodec(): RuleCodec
{
    return app(RuleCodec::class);
}

function rcScope(PromotionStage $stage = PromotionStage::Cart, ?string $currency = 'RUB'): RuleScope
{
    return new RuleScope($stage, $currency === null ? null : Currency::of($currency));
}

/** The error code and field of the InvalidCommand a call fails with. */
function rcFailure(Closure $call): string
{
    try {
        $call();
    } catch (InvalidCommand $e) {
        return "{$e->code()} @ {$e->field}";
    }

    return 'no error';
}

it('encodes rules with the key, the plugin version and canonical parameters', function (): void {
    $stored = rcCodec()->encode([new RuleSpec('test.min_items', ['min' => 3])], RuleCodec::CONDITION, rcScope());

    expect($stored)->toHaveCount(1)
        ->and($stored[0])->toBeInstanceOf(StoredRule::class)
        ->and($stored[0]->key)->toBe('test.min_items')
        ->and($stored[0]->version)->toBe(2)
        ->and($stored[0]->params->toArray())->toBe(['min' => 3]);

    $effects = rcCodec()->encode([new RuleSpec('test.flat_off', ['amount' => Money::of('10.00', 'RUB')])], RuleCodec::EFFECT, rcScope());

    expect($effects[0]->params->toArray())->toBe(['amount' => ['amount_minor' => 1000, 'currency' => 'RUB']]);
});

it('refuses an unknown key, naming the field', function (): void {
    expect(rcFailure(fn () => rcCodec()->encode([new RuleSpec('test.min_items', ['min' => 1]), new RuleSpec('acme.missing')], RuleCodec::CONDITION, rcScope())))
        ->toBe('promotions.unknown_rule @ conditions.1.key');
});

it('refuses a key of the other role', function (): void {
    expect(rcFailure(fn () => rcCodec()->encode([new RuleSpec('test.min_items', ['min' => 1])], RuleCodec::EFFECT, rcScope())))
        ->toBe('promotions.unknown_rule @ effects.0.key');
});

it('refuses a rule in a stage it does not support', function (): void {
    expect(rcFailure(fn () => rcCodec()->encode([new RuleSpec('test.min_items', ['min' => 1])], RuleCodec::CONDITION, rcScope(PromotionStage::Catalog))))
        ->toBe('promotions.rule_not_allowed_in_stage @ conditions.0.key')
        ->and(rcFailure(fn () => rcCodec()->encode([new RuleSpec('test.flat_off', ['amount' => Money::of('1.00', 'RUB')])], RuleCodec::EFFECT, rcScope(PromotionStage::Delivery))))
        ->toBe('promotions.rule_not_allowed_in_stage @ effects.0.key');
});

it('reports invalid parameters with the path of the field', function (): void {
    expect(rcFailure(fn () => rcCodec()->encode([new RuleSpec('test.min_items', ['min' => 0])], RuleCodec::CONDITION, rcScope())))
        ->toBe('promotions.invalid_rule_config @ conditions.0.params.min')
        ->and(rcFailure(fn () => rcCodec()->encode([new RuleSpec('test.min_items', ['min' => 2, 'extra' => 1])], RuleCodec::CONDITION, rcScope())))
        ->toBe('promotions.invalid_rule_config @ conditions.0.params.extra')
        ->and(rcFailure(fn () => rcCodec()->encode([new RuleSpec('test.flat_off', ['amount' => Money::of('1.00', 'USD')])], RuleCodec::EFFECT, rcScope())))
        ->toBe('promotions.invalid_rule_config @ effects.0.params.amount')
        ->and(rcFailure(fn () => rcCodec()->encode([new RuleSpec('test.flat_off', ['amount' => Money::of('1.00', 'RUB')])], RuleCodec::EFFECT, rcScope(currency: null))))
        ->toBe('promotions.invalid_rule_config @ effects.0.params.amount')
        ->and(rcFailure(fn () => rcCodec()->encode([new RuleSpec('test.min_items', ['min' => 1.5])], RuleCodec::CONDITION, rcScope())))
        ->toBe('promotions.invalid_rule_config @ conditions.0.params.min');
});

it('limits the number of rules: up to 20 conditions, 1 to 10 effects', function (): void {
    $conditions = static fn (int $count): array => array_fill(0, $count, new RuleSpec('test.min_items', ['min' => 1]));
    $effects = static fn (int $count): array => array_fill(0, $count, new RuleSpec('test.flat_off', ['amount' => Money::of('1.00', 'RUB')]));

    expect(rcCodec()->encode([], RuleCodec::CONDITION, rcScope()))->toBe([])
        ->and(rcCodec()->encode($conditions(20), RuleCodec::CONDITION, rcScope()))->toHaveCount(20)
        ->and(rcFailure(fn () => rcCodec()->encode($conditions(21), RuleCodec::CONDITION, rcScope())))->toBe('promotions.invalid_rules @ conditions')
        ->and(rcFailure(fn () => rcCodec()->encode([], RuleCodec::EFFECT, rcScope())))->toBe('promotions.invalid_rules @ effects')
        ->and(rcCodec()->encode($effects(10), RuleCodec::EFFECT, rcScope()))->toHaveCount(10)
        ->and(rcFailure(fn () => rcCodec()->encode($effects(11), RuleCodec::EFFECT, rcScope())))->toBe('promotions.invalid_rules @ effects');
});

it('decodes a rule stored at the current version as it is', function (): void {
    $loaded = rcCodec()->decode(new StoredRule('test.min_items', 2, RuleParams::fromInput(['min' => 4])), RuleCodec::CONDITION);

    expect($loaded->isSupported())->toBeTrue()
        ->and($loaded->rule)->toBeInstanceOf(PromotionCondition::class)
        ->and($loaded->params?->toArray())->toBe(['min' => 4])
        ->and($loaded->reason)->toBeNull();
});

it('upgrades the parameters of an older version', function (): void {
    $loaded = rcCodec()->decode(new StoredRule('test.min_items', 1, RuleParams::fromInput(['minimum' => 4])), RuleCodec::CONDITION);

    expect($loaded->isSupported())->toBeTrue()
        ->and($loaded->params?->toArray())->toBe(['min' => 4]);
});

it('does not throw on stored data: a missing key, a newer version or a failed upgrade is unsupported', function (string $key, int $version, array $params, string $role, string $reason): void {
    $loaded = rcCodec()->decode(new StoredRule($key, $version, RuleParams::fromInput($params)), $role);

    expect($loaded->isSupported())->toBeFalse()
        ->and($loaded->rule)->toBeNull()
        ->and($loaded->params)->toBeNull()
        ->and($loaded->reason)->toContain($reason);
})->with([
    'no plugin contributes the key' => ['acme.gone', 1, [], RuleCodec::EFFECT, "no effect contributes 'acme.gone'"],
    'the key belongs to the other role' => ['test.flat_off', 1, [], RuleCodec::CONDITION, "no condition contributes 'test.flat_off'"],
    'stored by a newer plugin' => ['test.min_items', 3, ['min' => 1], RuleCodec::CONDITION, 'stored at version 3, the plugin knows 2'],
    'the upgrade fails on the old shape' => ['test.min_items', 1, ['min' => 1], RuleCodec::CONDITION, 'cannot be upgraded'],
    'the plugin cannot read version 1' => ['test.newer', 1, [], RuleCodec::EFFECT, 'the old format is gone'],
]);
