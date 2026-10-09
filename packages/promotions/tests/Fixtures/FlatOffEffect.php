<?php

declare(strict_types=1);

namespace Commerz\Promotions\Tests\Fixtures;

use Commerz\Promotions\Rules\Contracts\PromotionEffect;
use Commerz\Promotions\Rules\Data\Discount;
use Commerz\Promotions\Rules\Data\EffectInput;
use Commerz\Promotions\Rules\Data\RuleParams;
use Commerz\Promotions\Rules\Data\RuleScope;
use Commerz\Promotions\Rules\Enums\PromotionStage;

/** A fixed amount off the order, in the promotion's currency. */
final class FlatOffEffect implements PromotionEffect
{
    public function key(): string
    {
        return 'test.flat_off';
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

        return RuleParams::fromInput(['amount' => $amount]);
    }

    public function upgrade(RuleParams $params, int $fromVersion): RuleParams
    {
        return $params;
    }

    public function apply(EffectInput $input): array
    {
        return [Discount::order($input->params->money('amount', $input->state->currency()))];
    }
}
