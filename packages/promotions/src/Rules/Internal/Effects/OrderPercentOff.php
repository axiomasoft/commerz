<?php

declare(strict_types=1);

namespace Commerz\Promotions\Rules\Internal\Effects;

use Commerz\Money\Rate;
use Commerz\Promotions\Rules\Contracts\PromotionEffect;
use Commerz\Promotions\Rules\Data\Discount;
use Commerz\Promotions\Rules\Data\EffectInput;
use Commerz\Promotions\Rules\Data\RuleParams;
use Commerz\Promotions\Rules\Data\RuleScope;
use Commerz\Promotions\Rules\Enums\PromotionStage;
use Commerz\Promotions\Rules\Exceptions\InvalidRuleConfig;

/** A percentage off what is left of the order: the items' net minus the order discounts already applied. */
final readonly class OrderPercentOff implements PromotionEffect
{
    public function key(): string
    {
        return 'order.percent_off';
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
        return RuleParams::fromInput(['rateBp' => $params->only(['rateBp'])->int('rateBp', 1, 10_000)]);
    }

    public function upgrade(RuleParams $params, int $fromVersion): RuleParams
    {
        throw new InvalidRuleConfig('version', "cannot upgrade {$this->key()} from version {$fromVersion}");
    }

    public function apply(EffectInput $input): array
    {
        $orderNet = OrderNet::of($input->state);

        if (! $orderNet->isPositive()) {
            return [];
        }

        $amount = $orderNet->percentage(Rate::bp($input->params->int('rateBp', 1, 10_000)), $input->rounding);

        return $amount->isPositive() ? [Discount::order($amount)] : [];
    }
}
