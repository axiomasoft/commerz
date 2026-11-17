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
use Packman\Attributes\Internal;

/** A percentage off the delivery price; 10000 makes the delivery free. Nothing without a delivery charge. */
#[Internal]
final readonly class DeliveryPercentOff implements PromotionEffect
{
    public function key(): string
    {
        return 'delivery.percent_off';
    }

    public function version(): int
    {
        return 1;
    }

    public function stages(): array
    {
        return [PromotionStage::Delivery];
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
        $delivery = $input->state->deliveryNet();

        if ($delivery === null || ! $delivery->isPositive()) {
            return [];
        }

        $amount = $delivery->percentage(Rate::bp($input->params->int('rateBp', 1, 10_000)), $input->rounding);

        return $amount->isPositive() ? [Discount::delivery($amount)] : [];
    }
}
