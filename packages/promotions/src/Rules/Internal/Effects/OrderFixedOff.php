<?php

declare(strict_types=1);

namespace Commerz\Promotions\Rules\Internal\Effects;

use Commerz\Promotions\Rules\Contracts\PromotionEffect;
use Commerz\Promotions\Rules\Data\Discount;
use Commerz\Promotions\Rules\Data\EffectInput;
use Commerz\Promotions\Rules\Data\RuleParams;
use Commerz\Promotions\Rules\Data\RuleScope;
use Commerz\Promotions\Rules\Enums\PromotionStage;
use Commerz\Promotions\Rules\Exceptions\InvalidRuleConfig;

/** A fixed amount off the order, never more than what is left of it. */
final readonly class OrderFixedOff implements PromotionEffect
{
    public function key(): string
    {
        return 'order.fixed_off';
    }

    public function version(): int
    {
        return 1;
    }

    public function stages(): array
    {
        return [PromotionStage::Cart];
    }

    public function normalize(RuleParams $params, RuleScope $scope): RuleParams
    {
        $amount = $params->only(['amount'])->money('amount', $scope->currencyFor('amount'));

        if (! $amount->isPositive()) {
            throw new InvalidRuleConfig('amount', 'must be greater than zero');
        }

        return RuleParams::fromInput(['amount' => $amount]);
    }

    public function upgrade(RuleParams $params, int $fromVersion): RuleParams
    {
        throw new InvalidRuleConfig('version', "cannot upgrade {$this->key()} from version {$fromVersion}");
    }

    public function apply(EffectInput $input): array
    {
        $amount = $input->params->money('amount', $input->state->currency());

        if (! $amount->isPositive()) {
            throw new InvalidRuleConfig('amount', 'must be greater than zero');
        }

        $orderNet = OrderNet::of($input->state);

        if (! $orderNet->isPositive()) {
            return [];
        }

        return [Discount::order($amount->compare($orderNet) > 0 ? $orderNet : $amount)];
    }
}
