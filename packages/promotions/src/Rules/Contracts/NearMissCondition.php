<?php

declare(strict_types=1);

namespace Commerz\Promotions\Rules\Contracts;

use Commerz\Promotions\Rules\Data\ConditionInput;
use Commerz\Quotes\Data\NearMissHint;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * Optional extension of {@see PromotionCondition} for threshold qualifiers that can report how close a quote is
 * to satisfying the condition (e.g. missing spend or missing quantity).
 */
#[Spi(kind: SpiKind::Implement, since: '0.1')]
interface NearMissCondition extends PromotionCondition
{
    /**
     * Returns a {@see NearMissHint} when the condition is not yet satisfied and a positive gap can be quantified,
     * or null when already satisfied or not applicable.
     */
    public function nearMiss(ConditionInput $input): ?NearMissHint;
}
