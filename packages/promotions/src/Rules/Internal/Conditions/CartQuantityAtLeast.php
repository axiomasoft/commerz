<?php

declare(strict_types=1);

namespace Commerz\Promotions\Rules\Internal\Conditions;

use Commerz\Promotions\Rules\Contracts\PromotionCondition;
use Commerz\Promotions\Rules\Data\ConditionInput;
use Commerz\Promotions\Rules\Data\ConditionOutcome;
use Commerz\Promotions\Rules\Data\RuleParams;
use Commerz\Promotions\Rules\Data\RuleScope;
use Commerz\Promotions\Rules\Enums\ConditionRole;
use Commerz\Promotions\Rules\Enums\PromotionStage;
use Commerz\Promotions\Rules\Exceptions\InvalidRuleConfig;

/** The number of units reaches the quantity; `lines` picks all the lines or the eligible ones. */
final readonly class CartQuantityAtLeast implements PromotionCondition
{
    public function key(): string
    {
        return 'cart.quantity_at_least';
    }

    public function version(): int
    {
        return 1;
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
        $params->only(['quantity', 'lines']);

        return RuleParams::fromInput([
            'quantity' => $params->int('quantity', 1, 1_000_000),
            'lines' => $params->choice('lines', ['all', 'eligible'], 'all'),
        ]);
    }

    public function upgrade(RuleParams $params, int $fromVersion): RuleParams
    {
        throw new InvalidRuleConfig('version', "cannot upgrade {$this->key()} from version {$fromVersion}");
    }

    public function evaluate(ConditionInput $input): ConditionOutcome
    {
        $wanted = $input->params->int('quantity', 1, 1_000_000);
        $onlyEligible = $input->params->choice('lines', ['all', 'eligible'], 'all') === 'eligible';
        $quantity = 0;

        foreach ($input->state->lines as $line) {
            if (! $onlyEligible || in_array($line->lineId, $input->lines, true)) {
                $quantity += $line->quantity;
            }
        }

        return $quantity >= $wanted
            ? ConditionOutcome::pass()
            : ConditionOutcome::fail('quantity_not_reached', "The cart has fewer than {$wanted} units.");
    }
}
