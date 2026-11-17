<?php

declare(strict_types=1);

namespace Commerz\Promotions\Rules\Data;

use Commerz\Quotes\Data\QuoteState;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class ConditionInput
{
    /** @param  list<string>  $lines  ids of the lines still eligible, in the order of `$state->lines` */
    public function __construct(
        public QuoteState $state,
        public PromotionSummary $promotion,
        public RuleParams $params,
        public array $lines,
    ) {}
}
