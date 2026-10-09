<?php

declare(strict_types=1);

namespace Commerz\Promotions\Tests\Fixtures;

use Commerz\Promotions\Rules\Contracts\PromotionCondition;
use Commerz\Promotions\Rules\Data\ConditionInput;
use Commerz\Promotions\Rules\Data\ConditionOutcome;
use Commerz\Promotions\Rules\Data\RuleParams;
use Commerz\Promotions\Rules\Data\RuleScope;
use Commerz\Promotions\Rules\Enums\ConditionRole;
use Commerz\Promotions\Rules\Enums\PromotionStage;
use Commerz\Promotions\Rules\Exceptions\InvalidRuleConfig;

/** Version 2 keeps `min`; version 1 kept it as `minimum`. */
final class MinItemsCondition implements PromotionCondition
{
    public function key(): string
    {
        return 'test.min_items';
    }

    public function version(): int
    {
        return 2;
    }

    public function stages(): array
    {
        return [PromotionStage::Cart, PromotionStage::Delivery];
    }

    public function role(): ConditionRole
    {
        return ConditionRole::Qualifier;
    }

    public function normalize(RuleParams $params, RuleScope $scope): RuleParams
    {
        return RuleParams::fromInput(['min' => $params->only(['min'])->int('min', 1, 1000)]);
    }

    public function upgrade(RuleParams $params, int $fromVersion): RuleParams
    {
        if ($fromVersion !== 1) {
            throw new InvalidRuleConfig('version', "cannot upgrade from {$fromVersion}");
        }

        return RuleParams::fromInput(['min' => $params->only(['minimum'])->int('minimum', 1, 1000)]);
    }

    public function evaluate(ConditionInput $input): ConditionOutcome
    {
        return count($input->state->lines) >= $input->params->int('min')
            ? ConditionOutcome::pass()
            : ConditionOutcome::fail('too_few_items', 'The cart has too few items.');
    }
}
