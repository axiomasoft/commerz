<?php

declare(strict_types=1);

use Commerz\Errors\InvalidCommand;
use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Promotions\Definitions\Data\PromotionId;
use Commerz\Promotions\Rules\Data\Activation;
use Commerz\Promotions\Rules\Data\CodeRejection;
use Commerz\Promotions\Rules\Data\ConditionOutcome;
use Commerz\Promotions\Rules\Data\Discount;
use Commerz\Promotions\Rules\Data\RuleScope;
use Commerz\Promotions\Rules\Data\RuleSpec;
use Commerz\Promotions\Rules\Enums\PromotionStage;
use Commerz\Promotions\Rules\Exceptions\InvalidRuleConfig;
use Commerz\Promotions\Rules\Exceptions\InvalidRuleResult;
use Commerz\Quotes\Enums\AdjustmentTarget;
use Commerz\Quotes\Enums\QuoteAnchor;
use Packman\Errors\Enums\ErrorCategory;

/**
 * The error code of the InvalidCommand a call fails with, or null.
 *
 * @param  Closure(): mixed  $call
 */
function rdCode(Closure $call): ?string
{
    try {
        $call();
    } catch (InvalidCommand $e) {
        return $e->code();
    }

    return null;
}

it('maps every stage to its quote anchor and stage key', function (): void {
    expect(PromotionStage::Catalog->anchor())->toBe(QuoteAnchor::CatalogPromotions)
        ->and(PromotionStage::Cart->anchor())->toBe(QuoteAnchor::CartPromotions)
        ->and(PromotionStage::Delivery->anchor())->toBe(QuoteAnchor::DeliveryPromotions)
        ->and(array_map(static fn (PromotionStage $s): string => $s->stageKey(), PromotionStage::cases()))
        ->toBe(['promotions.catalog', 'promotions.cart', 'promotions.delivery']);
});

it('accepts rule keys of the built-in and plugin shapes', function (string $key): void {
    expect((new RuleSpec($key))->key)->toBe($key);
})->with(['cart.subtotal_at_least', 'buy_x_get_y', 'acme.vip_only', 'a.b.c1']);

it('refuses a malformed rule key', function (string $key): void {
    expect(rdCode(fn () => new RuleSpec($key)))->toBe('promotions.invalid_rule');
})->with(['', 'Cart.Total', '.x', 'x.', 'a b', '1abc', str_repeat('a', 65)]);

it('asks for the promotion currency when a rule needs one', function (): void {
    $scope = new RuleScope(PromotionStage::Cart, Currency::of('RUB'));

    expect($scope->currencyFor('amount')->code)->toBe('RUB')
        ->and(fn () => new RuleScope(PromotionStage::Cart, null)->currencyFor('amount'))
        ->toThrow(fn (InvalidRuleConfig $e) => expect($e->field)->toBe('amount')->and($e->reason)->toBe('set the promotion currency'));
});

it('keeps a discount positive and names where it goes', function (): void {
    $line = Discount::line('a', Money::of('1.00', 'RUB'));
    $order = Discount::order(Money::zero('RUB'));
    $delivery = Discount::delivery(Money::of('5.00', 'RUB'));

    expect([$line->target, $line->lineId])->toBe([AdjustmentTarget::Line, 'a'])
        ->and([$order->target, $order->lineId])->toBe([AdjustmentTarget::Order, null])
        ->and([$delivery->target, $delivery->lineId])->toBe([AdjustmentTarget::Delivery, null])
        ->and(rdCode(fn () => Discount::order(Money::of('-1.00', 'RUB'))))->toBe('promotions.invalid_discount')
        ->and(rdCode(fn () => Discount::line('a', Money::ofMinor(-1, 'RUB'))))->toBe('promotions.invalid_discount');
});

it('passes or fails a condition with a snake_case reason', function (): void {
    $pass = ConditionOutcome::pass();
    $filter = ConditionOutcome::pass(['a', 'c']);
    $fail = ConditionOutcome::fail('minimum_not_reached', 'Add 5.00 more.');

    expect([$pass->passed, $pass->lines])->toBe([true, null])
        ->and([$filter->passed, $filter->lines])->toBe([true, ['a', 'c']])
        ->and([$fail->passed, $fail->lines, $fail->reason, $fail->message])->toBe([false, null, 'minimum_not_reached', 'Add 5.00 more.'])
        ->and(rdCode(fn () => ConditionOutcome::fail('Minimum Not Reached', 'x')))->toBe('promotions.invalid_condition_outcome');
});

it('checks the shape of an activation and a code rejection', function (): void {
    $id = PromotionId::generate();

    expect((new Activation($id, 'SAVE10', 'save10'))->couponCode)->toBe('SAVE10')
        ->and((new Activation($id))->presentedCode)->toBeNull()
        ->and(rdCode(fn () => new Activation($id, '')))->toBe('promotions.invalid_activation')
        ->and(rdCode(fn () => new Activation($id, 'SAVE10', '')))->toBe('promotions.invalid_activation')
        ->and((new CodeRejection('nope', 'unknown_code', 'No such code.'))->promotion)->toBeNull()
        ->and((new CodeRejection('save10', 'coupon_disabled', 'Disabled.', $id))->promotion)->toBe($id)
        ->and(rdCode(fn () => new CodeRejection('x', 'Unknown', 'm')))->toBe('promotions.invalid_activation');
});

it('classifies the rule errors', function (): void {
    $config = new InvalidRuleConfig('min', 'must be positive');
    $result = new InvalidRuleResult('acme.vip_only', 'negative discount');

    expect([$config->code(), $config->category(), $config->context()])->toBe(['promotions.invalid_rule_config', ErrorCategory::Validation, ['field' => 'min', 'reason' => 'must be positive']])
        ->and([$result->code(), $result->category(), $result->context()])->toBe(['promotions.invalid_rule_result', ErrorCategory::Unavailable, ['rule' => 'acme.vip_only', 'reason' => 'negative discount']]);
});
