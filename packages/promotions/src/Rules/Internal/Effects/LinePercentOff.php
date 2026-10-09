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

/** A percentage off the current net of every eligible line. */
final readonly class LinePercentOff implements PromotionEffect
{
    public function key(): string
    {
        return 'line.percent_off';
    }

    public function version(): int
    {
        return 1;
    }

    public function stages(): array
    {
        return [PromotionStage::Catalog, PromotionStage::Cart];
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
        $rate = Rate::bp($input->params->int('rateBp', 1, 10_000));
        $discounts = [];

        foreach ($input->lines as $lineId) {
            $amount = $input->state->lineNet($lineId)->percentage($rate, $input->rounding);

            if ($amount->isPositive()) {
                $discounts[] = Discount::line($lineId, $amount);
            }
        }

        return $discounts;
    }
}
