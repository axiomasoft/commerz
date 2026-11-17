<?php

declare(strict_types=1);

namespace Commerz\Promotions\Rules\Contracts;

use Commerz\Promotions\Rules\Data\Discount;
use Commerz\Promotions\Rules\Data\EffectInput;
use Commerz\Promotions\Rules\Data\RuleParams;
use Commerz\Promotions\Rules\Data\RuleScope;
use Commerz\Promotions\Rules\Enums\PromotionStage;
use Commerz\Promotions\Rules\Exceptions\InvalidRuleConfig;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * What a promotion takes off. The engine checks every discount (a known line, the quote currency, never above the
 * remaining price) and applies a promotion whole or not at all.
 */
#[Spi(kind: SpiKind::Implement, since: '0.1')]
interface PromotionEffect
{
    public const string KIND = 'promotion_effect';

    /** Contribution key, e.g. `order.percent_off` or `acme.cashback_cap`. */
    public function key(): string;

    /** The current version of the parameter schema, at least 1. */
    public function version(): int;

    /** @return non-empty-list<PromotionStage> the stages the rule may be used in */
    public function stages(): array;

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

    /**
     * Does not write and makes no network call; time comes from the quote context.
     *
     * @return list<Discount>
     */
    public function apply(EffectInput $input): array;
}
