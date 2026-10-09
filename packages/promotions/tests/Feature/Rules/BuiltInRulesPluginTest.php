<?php

declare(strict_types=1);

use Commerz\Plugins\Contracts\Contributions;
use Commerz\Promotions\Definitions\Data\PromotionId;
use Commerz\Promotions\Rules\Contracts\PromotionActivator;
use Commerz\Promotions\Rules\Contracts\PromotionCondition;
use Commerz\Promotions\Rules\Contracts\PromotionEffect;
use Commerz\Promotions\Rules\Data\ActivationRequest;
use Commerz\Promotions\Rules\Data\PromotionSummary;
use Commerz\Promotions\Rules\Enums\PromotionStage;
use Commerz\Promotions\Rules\Internal\Activators\AutomaticActivator;
use Commerz\Promotions\Tests\Support\RuleStates;

it('contributes the built-in conditions, effects and activator under their keys', function (): void {
    $contributions = app(Contributions::class);

    foreach (['line.sellable_in', 'line.category_in', 'customer.in_group', 'cart.subtotal_at_least', 'cart.quantity_at_least'] as $key) {
        expect($contributions->has(PromotionCondition::KIND, $key))->toBeTrue("condition {$key}")
            ->and($contributions->get(PromotionCondition::KIND, $key, PromotionCondition::class))->toBeInstanceOf(PromotionCondition::class);
    }

    foreach (['line.percent_off', 'line.fixed_off', 'order.percent_off', 'order.fixed_off', 'delivery.percent_off', 'buy_x_get_y'] as $key) {
        expect($contributions->has(PromotionEffect::KIND, $key))->toBeTrue("effect {$key}")
            ->and($contributions->get(PromotionEffect::KIND, $key, PromotionEffect::class))->toBeInstanceOf(PromotionEffect::class);
    }

    expect($contributions->has(PromotionActivator::KIND, 'automatic'))->toBeTrue()
        ->and($contributions->get(PromotionActivator::KIND, 'automatic', PromotionActivator::class))->toBeInstanceOf(AutomaticActivator::class)
        ->and($contributions->has(PromotionActivator::KIND, 'coupon'))->toBeTrue();
});

it('registers each key once: the built-ins agree with their own key()', function (): void {
    $contributions = app(Contributions::class);

    $kinds = [
        PromotionCondition::KIND => PromotionCondition::class,
        PromotionEffect::KIND => PromotionEffect::class,
        PromotionActivator::KIND => PromotionActivator::class,
    ];

    foreach ($kinds as $kind => $interface) {
        foreach ($contributions->keys($kind) as $key) {
            expect($contributions->get($kind, $key, $interface)->key())->toBe($key);
        }
    }

    expect($contributions->keys(PromotionCondition::KIND))->toHaveCount(5)
        ->and($contributions->keys(PromotionEffect::KIND))->toHaveCount(6)
        ->and($contributions->keys(PromotionActivator::KIND))->toBe(['automatic', 'coupon']);
});

it('switches on every candidate of the automatic activator and rejects nothing', function (): void {
    $first = PromotionId::generate();
    $second = PromotionId::generate();
    $summaries = [
        new PromotionSummary($first, 'a', 'A', PromotionStage::Cart, 'automatic', 10, RuleStates::currency()),
        new PromotionSummary($second, 'b', 'B', PromotionStage::Cart, 'automatic', 5, RuleStates::currency()),
    ];
    $context = RuleStates::state([['id' => '1', 'unit' => '1.00']])->context();

    $result = (new AutomaticActivator)->activate(new ActivationRequest($context, ['IGNORED'], $summaries));

    expect($result->rejections)->toBe([])
        ->and(array_map(static fn ($a): string => (string) $a->promotion, $result->activations))->toBe([(string) $first, (string) $second])
        ->and($result->activations[0]->couponCode)->toBeNull()
        ->and($result->activations[0]->presentedCode)->toBeNull();

    expect((new AutomaticActivator)->activate(new ActivationRequest($context, [], []))->activations)->toBe([]);
});
