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

/** A line filter that keeps a line it was not given (`foreign`) or passes without a list (`no_list`). */
final class RogueLineFilter implements PromotionCondition
{
    public function key(): string
    {
        return 'test.rogue_filter';
    }

    public function version(): int
    {
        return 1;
    }

    public function stages(): array
    {
        return [PromotionStage::Catalog, PromotionStage::Cart, PromotionStage::Delivery];
    }

    public function role(): ConditionRole
    {
        return ConditionRole::LineFilter;
    }

    public function normalize(RuleParams $params, RuleScope $scope): RuleParams
    {
        return RuleParams::fromInput(['mode' => $params->only(['mode'])->choice('mode', ['foreign', 'no_list'])]);
    }

    public function upgrade(RuleParams $params, int $fromVersion): RuleParams
    {
        return $params;
    }

    public function evaluate(ConditionInput $input): ConditionOutcome
    {
        return $input->params->string('mode') === 'foreign'
            ? ConditionOutcome::pass([...$input->lines, 'ghost'])
            : ConditionOutcome::pass();
    }
}
