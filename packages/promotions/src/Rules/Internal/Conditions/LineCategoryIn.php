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

/** Keeps the lines whose sellable belongs to one of the listed category codes (a snapshot lists its ancestors too). */
final readonly class LineCategoryIn implements PromotionCondition
{
    private const string CODE = '/\A[a-z0-9][a-z0-9_.\-]{0,63}\z/';

    public function key(): string
    {
        return 'line.category_in';
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
        $categories = array_values(array_unique($params->only(['categories'])->stringList('categories', 1, 100, self::CODE)));
        sort($categories, SORT_STRING);

        return RuleParams::fromInput(['categories' => $categories]);
    }

    public function upgrade(RuleParams $params, int $fromVersion): RuleParams
    {
        throw new InvalidRuleConfig('version', "cannot upgrade {$this->key()} from version {$fromVersion}");
    }

    public function evaluate(ConditionInput $input): ConditionOutcome
    {
        $wanted = $input->params->stringList('categories', 1, 100);
        $eligible = [];

        foreach ($input->state->lines as $line) {
            if (in_array($line->lineId, $input->lines, true) && array_intersect($line->sellable->categories, $wanted) !== []) {
                $eligible[] = $line->lineId;
            }
        }

        return $eligible === []
            ? ConditionOutcome::fail('no_eligible_lines', 'No line of the cart belongs to the promotion\'s categories.')
            : ConditionOutcome::pass($eligible);
    }
}
