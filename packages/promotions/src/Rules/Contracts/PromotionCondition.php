<?php

declare(strict_types=1);

namespace Commerz\Promotions\Rules\Contracts;

use Commerz\Attributes\Spi;
use Commerz\Attributes\SpiKind;
use Commerz\Promotions\Rules\Data\ConditionInput;
use Commerz\Promotions\Rules\Data\ConditionOutcome;
use Commerz\Promotions\Rules\Data\RuleParams;
use Commerz\Promotions\Rules\Data\RuleScope;
use Commerz\Promotions\Rules\Enums\ConditionRole;
use Commerz\Promotions\Rules\Enums\PromotionStage;
use Commerz\Promotions\Rules\Exceptions\InvalidRuleConfig;

/**
 * A condition a promotion needs before its effects apply. Stored by key and `version()`, never by class: a rule
 * whose plugin is gone, or whose parameters cannot be upgraded, makes its promotion `unsupported` and not applied.
 */
#[Spi(kind: SpiKind::Implement, since: '0.1')]
interface PromotionCondition
{
    public const string KIND = 'promotion_condition';

    /** Contribution key, e.g. `cart.subtotal_at_least` or `acme.vip_only`. */
    public function key(): string;

    /** The current version of the parameter schema, at least 1. */
    public function version(): int;

    /** @return non-empty-list<PromotionStage> the stages the rule may be used in */
    public function stages(): array;

    public function role(): ConditionRole;

    /**
     * Validates the parameters and returns them in canonical form.
     *
     * @throws InvalidRuleConfig
     */
    public function normalize(RuleParams $params, RuleScope $scope): RuleParams;

    /**
     * Brings parameters stored at an older version to the current one.
     *
     * @throws InvalidRuleConfig when they cannot be upgraded; the rule is then unsupported
     */
    public function upgrade(RuleParams $params, int $fromVersion): RuleParams;

    /** Does not write and makes no network call; time comes from the quote context. */
    public function evaluate(ConditionInput $input): ConditionOutcome;
}
