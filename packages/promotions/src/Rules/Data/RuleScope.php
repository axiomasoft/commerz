<?php

declare(strict_types=1);

namespace Commerz\Promotions\Rules\Data;

use Commerz\Attributes\Api;
use Commerz\Money\Currency;
use Commerz\Promotions\Rules\Enums\PromotionStage;
use Commerz\Promotions\Rules\Exceptions\InvalidRuleConfig;

/** What a rule is validated against: the stage of its promotion and the promotion's currency, if it has one. */
#[Api(since: '0.1')]
final readonly class RuleScope
{
    public function __construct(public PromotionStage $stage, public ?Currency $currency) {}

    /**
     * The currency of an amount parameter.
     *
     * @throws InvalidRuleConfig when the promotion has no currency
     */
    public function currencyFor(string $field): Currency
    {
        return $this->currency ?? throw new InvalidRuleConfig($field, 'set the promotion currency');
    }
}
