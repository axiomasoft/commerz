<?php

declare(strict_types=1);

namespace Commerz\Promotions\Rules\Internal\Conditions;

use Commerz\Money\Money;
use Commerz\Promotions\Rules\Contracts\PromotionCondition;
use Commerz\Promotions\Rules\Data\ConditionInput;
use Commerz\Promotions\Rules\Data\ConditionOutcome;
use Commerz\Promotions\Rules\Data\RuleParams;
use Commerz\Promotions\Rules\Data\RuleScope;
use Commerz\Promotions\Rules\Enums\ConditionRole;
use Commerz\Promotions\Rules\Enums\PromotionStage;
use Commerz\Promotions\Rules\Exceptions\InvalidRuleConfig;
use Packman\Attributes\Internal;

/** The net of the lines (after the discounts already applied) reaches the amount; `lines` picks all of them or the eligible ones. */
#[Internal]
final readonly class CartSubtotalAtLeast implements PromotionCondition
{
    public function key(): string
    {
        return 'cart.subtotal_at_least';
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
        $params->only(['amount', 'lines']);
        $amount = $params->money('amount', $scope->currencyFor('amount'));

        if (! $amount->isPositive()) {
            throw new InvalidRuleConfig('amount', 'must be greater than zero');
        }

        return RuleParams::fromInput([
            'amount' => $amount,
            'lines' => $params->choice('lines', ['all', 'eligible'], 'all'),
        ]);
    }

    public function upgrade(RuleParams $params, int $fromVersion): RuleParams
    {
        throw new InvalidRuleConfig('version', "cannot upgrade {$this->key()} from version {$fromVersion}");
    }

    public function evaluate(ConditionInput $input): ConditionOutcome
    {
        $currency = $input->state->currency();
        $minimum = $input->params->money('amount', $currency);

        if (! $minimum->isPositive()) {
            throw new InvalidRuleConfig('amount', 'must be greater than zero');
        }

        $onlyEligible = $input->params->choice('lines', ['all', 'eligible'], 'all') === 'eligible';
        $subtotal = Money::zero($currency);

        foreach ($input->state->lines as $line) {
            if (! $onlyEligible || in_array($line->lineId, $input->lines, true)) {
                $subtotal = $subtotal->plus($input->state->lineNet($line->lineId));
            }
        }

        return $subtotal->compare($minimum) >= 0
            ? ConditionOutcome::pass()
            : ConditionOutcome::fail('minimum_not_reached', "The cart is below the minimum of {$minimum->toDecimal()} {$currency->code}.");
    }
}
