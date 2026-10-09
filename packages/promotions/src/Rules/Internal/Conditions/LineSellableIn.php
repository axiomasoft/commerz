<?php

declare(strict_types=1);

namespace Commerz\Promotions\Rules\Internal\Conditions;

use Commerz\Errors\InvalidCommand;
use Commerz\Identity\SellableRef;
use Commerz\Promotions\Rules\Contracts\PromotionCondition;
use Commerz\Promotions\Rules\Data\ConditionInput;
use Commerz\Promotions\Rules\Data\ConditionOutcome;
use Commerz\Promotions\Rules\Data\RuleParams;
use Commerz\Promotions\Rules\Data\RuleScope;
use Commerz\Promotions\Rules\Enums\ConditionRole;
use Commerz\Promotions\Rules\Enums\PromotionStage;
use Commerz\Promotions\Rules\Exceptions\InvalidRuleConfig;

/** Keeps the lines whose sellable is one of the listed refs. */
final readonly class LineSellableIn implements PromotionCondition
{
    public function key(): string
    {
        return 'line.sellable_in';
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
        $refs = [];

        foreach ($params->only(['sellables'])->stringList('sellables', 1, 1000) as $ref) {
            try {
                $refs[] = (string) SellableRef::fromString($ref);
            } catch (InvalidCommand) {
                throw new InvalidRuleConfig('sellables', "'{$ref}' is not a sellable ref (type:id)");
            }
        }

        $refs = array_values(array_unique($refs));
        sort($refs, SORT_STRING);

        return RuleParams::fromInput(['sellables' => $refs]);
    }

    public function upgrade(RuleParams $params, int $fromVersion): RuleParams
    {
        throw new InvalidRuleConfig('version', "cannot upgrade {$this->key()} from version {$fromVersion}");
    }

    public function evaluate(ConditionInput $input): ConditionOutcome
    {
        $allowed = array_flip($input->params->stringList('sellables', 1, 1000));
        $eligible = [];

        foreach ($input->state->lines as $line) {
            if (in_array($line->lineId, $input->lines, true) && isset($allowed[(string) $line->sellable->ref])) {
                $eligible[] = $line->lineId;
            }
        }

        return $eligible === []
            ? ConditionOutcome::fail('no_eligible_lines', 'No line of the cart is one of the promotion\'s products.')
            : ConditionOutcome::pass($eligible);
    }
}
