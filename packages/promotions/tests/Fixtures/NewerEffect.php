<?php

declare(strict_types=1);

namespace Commerz\Promotions\Tests\Fixtures;

use Commerz\Promotions\Rules\Contracts\PromotionEffect;
use Commerz\Promotions\Rules\Data\EffectInput;
use Commerz\Promotions\Rules\Data\RuleParams;
use Commerz\Promotions\Rules\Data\RuleScope;
use Commerz\Promotions\Rules\Enums\PromotionStage;
use Commerz\Promotions\Rules\Exceptions\InvalidRuleConfig;

/** At version 3, and unable to read what version 1 stored. */
final class NewerEffect implements PromotionEffect
{
    public function key(): string
    {
        return 'test.newer';
    }

    public function version(): int
    {
        return 3;
    }

    public function stages(): array
    {
        return [PromotionStage::Cart, PromotionStage::Catalog];
    }

    public function normalize(RuleParams $params, RuleScope $scope): RuleParams
    {
        return $params;
    }

    public function upgrade(RuleParams $params, int $fromVersion): RuleParams
    {
        throw new InvalidRuleConfig('legacy', 'the old format is gone');
    }

    public function apply(EffectInput $input): array
    {
        return [];
    }
}
