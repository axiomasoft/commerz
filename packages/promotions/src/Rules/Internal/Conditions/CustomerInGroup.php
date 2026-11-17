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
use Packman\Attributes\Internal;

/** The buyer is in at least one of the listed customer groups. */
#[Internal]
final readonly class CustomerInGroup implements PromotionCondition
{
    private const string GROUP = '/\A[a-z0-9_.\-]{1,64}\z/';

    public function key(): string
    {
        return 'customer.in_group';
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
        return ConditionRole::Qualifier;
    }

    public function normalize(RuleParams $params, RuleScope $scope): RuleParams
    {
        $groups = array_values(array_unique($params->only(['groups'])->stringList('groups', 1, 50, self::GROUP)));
        sort($groups, SORT_STRING);

        return RuleParams::fromInput(['groups' => $groups]);
    }

    public function upgrade(RuleParams $params, int $fromVersion): RuleParams
    {
        throw new InvalidRuleConfig('version', "cannot upgrade {$this->key()} from version {$fromVersion}");
    }

    public function evaluate(ConditionInput $input): ConditionOutcome
    {
        return array_intersect($input->state->context()->customerGroups, $input->params->stringList('groups', 1, 50)) !== []
            ? ConditionOutcome::pass()
            : ConditionOutcome::fail('customer_group_mismatch', 'The customer is not in a group this promotion is for.');
    }
}
