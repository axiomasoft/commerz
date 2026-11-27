<?php

declare(strict_types=1);

use Commerz\Identity\SellableRef;
use Commerz\Money\Money;
use Commerz\Money\RoundingMode;
use Commerz\Promotions\Definitions\Data\PromotionId;
use Commerz\Promotions\Rules\Data\ConditionInput;
use Commerz\Promotions\Rules\Data\EffectInput;
use Commerz\Promotions\Rules\Data\PromotionSummary;
use Commerz\Promotions\Rules\Data\RuleParams;
use Commerz\Promotions\Rules\Data\RuleScope;
use Commerz\Promotions\Rules\Enums\PromotionStage;
use Commerz\Promotions\Rules\Exceptions\InvalidRuleConfig;
use Commerz\Promotions\Rules\Internal\Conditions\CartQuantityAtLeast;
use Commerz\Promotions\Rules\Internal\Conditions\CartSubtotalAtLeast;
use Commerz\Promotions\Rules\Internal\Effects\AutoAddGiftLine;
use Commerz\Promotions\Tests\Support\RuleStates;

it('normalizes AutoAddGiftLine parameters and defaults mode by sellable count', function (): void {
    $effect = new AutoAddGiftLine;
    $scope = new RuleScope(PromotionStage::Cart, RuleStates::currency());

    $single = $effect->normalize(RuleParams::fromInput(['sellables' => ['variant:mug-1']]), $scope);
    expect($single->toArray())->toBe([
        'maxSelections' => 1,
        'mode' => 'auto',
        'quantity' => 1,
        'sellables' => ['variant:mug-1'],
        'sku' => null,
        'title' => null,
    ]);

    $multi = $effect->normalize(RuleParams::fromInput([
        'sellables' => ['variant:mug-1', 'variant:tote-2'],
        'maxSelections' => 2,
        'quantity' => 1,
        'title' => 'Choose your free gift',
    ]), $scope);
    expect($multi->toArray()['mode'])->toBe('selectable')
        ->and($multi->toArray()['maxSelections'])->toBe(2);

    expect(fn () => $effect->normalize(RuleParams::fromInput([
        'sellables' => ['variant:mug-1'],
        'mode' => 'selectable',
        'maxSelections' => 2,
    ]), $scope))->toThrow(InvalidRuleConfig::class, 'maxSelections');
});

it('produces zero-priced gift lines in auto mode and SelectableGiftOffer in selectable mode', function (): void {
    $effect = new AutoAddGiftLine;
    $scope = new RuleScope(PromotionStage::Cart, RuleStates::currency());
    $state = RuleStates::state([['id' => '1', 'unit' => '500.00']]);
    $promotion = new PromotionSummary(PromotionId::generate(), 'gift-promo', 'Gift Promo', PromotionStage::Cart, 'automatic', 0, RuleStates::currency());

    $autoParams = $effect->normalize(RuleParams::fromInput([
        'sellables' => ['variant:free-mug'],
        'quantity' => 2,
        'mode' => 'auto',
        'sku' => 'GIFT-MUG',
        'title' => 'Free Mug',
    ]), $scope);

    $autoResult = $effect->applyGifts(new EffectInput($state, $promotion, $autoParams, ['1'], RoundingMode::HalfUp));
    expect($effect->apply(new EffectInput($state, $promotion, $autoParams, ['1'], RoundingMode::HalfUp)))->toBe([])
        ->and($autoResult->giftOffers)->toBe([])
        ->and($autoResult->giftLines)->toHaveCount(1)
        ->and($autoResult->giftLines[0]->isGift)->toBeTrue()
        ->and($autoResult->giftLines[0]->unitPrice->isZero())->toBeTrue()
        ->and($autoResult->giftLines[0]->quantity)->toBe(2)
        ->and($autoResult->giftLines[0]->sellable->sku)->toBe('GIFT-MUG')
        ->and($autoResult->giftLines[0]->giftPromotionId)->toBe($promotion->id->value);

    $selectableParams = $effect->normalize(RuleParams::fromInput([
        'sellables' => ['variant:mug', 'variant:bottle'],
        'quantity' => 1,
        'mode' => 'selectable',
        'maxSelections' => 1,
        'title' => 'Pick a free gift',
    ]), $scope);

    $selectableResult = $effect->applyGifts(
        new EffectInput($state, $promotion, $selectableParams, ['1'], RoundingMode::HalfUp),
        couponCode: 'GIFTME',
    );
    expect($selectableResult->giftLines)->toBe([])
        ->and($selectableResult->giftOffers)->toHaveCount(1)
        ->and($selectableResult->giftOffers[0]->title)->toBe('Pick a free gift')
        ->and($selectableResult->giftOffers[0]->couponCode)->toBe('GIFTME')
        ->and(array_map(static fn (SellableRef $ref): string => (string) $ref, $selectableResult->giftOffers[0]->sellableRefs))
        ->toBe(['variant:mug', 'variant:bottle']);
});

it('computes near-miss hints for CartSubtotalAtLeast and CartQuantityAtLeast', function (): void {
    $state = RuleStates::state([['id' => '1', 'unit' => '800.00', 'quantity' => 1]]);
    $promotion = new PromotionSummary(PromotionId::generate(), 'gift-promo', 'Gift Promo', PromotionStage::Cart, 'automatic', 0, RuleStates::currency());

    $subtotalCondition = new CartSubtotalAtLeast;
    $subtotalHint = $subtotalCondition->nearMiss(new ConditionInput(
        $state,
        $promotion,
        RuleParams::fromInput(['amount' => Money::of('1000.00', 'RUB'), 'lines' => 'all']),
        ['1'],
    ));

    expect($subtotalHint)->not->toBeNull()
        ->and($subtotalHint?->reason)->toBe('minimum_not_reached')
        ->and($subtotalHint?->missingAmount?->toDecimal())->toBe('200.00')
        ->and($subtotalHint?->thresholdAmount?->toDecimal())->toBe('1000.00');

    $qtyCondition = new CartQuantityAtLeast;
    $qtyHint = $qtyCondition->nearMiss(new ConditionInput(
        $state,
        $promotion,
        RuleParams::fromInput(['quantity' => 3, 'lines' => 'all']),
        ['1'],
    ));

    expect($qtyHint)->not->toBeNull()
        ->and($qtyHint?->reason)->toBe('quantity_not_reached')
        ->and($qtyHint?->missingQuantity)->toBe(2)
        ->and($qtyHint?->thresholdQuantity)->toBe(3);

    // Already satisfied returns null
    expect($qtyCondition->nearMiss(new ConditionInput(
        $state,
        $promotion,
        RuleParams::fromInput(['quantity' => 1, 'lines' => 'all']),
        ['1'],
    )))->toBeNull();
});
