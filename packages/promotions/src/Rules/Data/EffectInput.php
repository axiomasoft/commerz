<?php

declare(strict_types=1);

namespace Commerz\Promotions\Rules\Data;

use Commerz\Attributes\Api;
use Commerz\Money\RoundingMode;
use Commerz\Quotes\Data\QuoteState;

#[Api(since: '0.1')]
final readonly class EffectInput
{
    /**
     * @param  list<string>  $lines  ids of the lines the conditions left eligible
     * @param  RoundingMode  $rounding  the configured mode for percentage effects
     */
    public function __construct(
        public QuoteState $state,
        public PromotionSummary $promotion,
        public RuleParams $params,
        public array $lines,
        public RoundingMode $rounding,
    ) {}
}
