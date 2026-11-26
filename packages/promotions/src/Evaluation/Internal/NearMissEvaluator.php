<?php

declare(strict_types=1);

namespace Commerz\Promotions\Evaluation\Internal;

use Commerz\Quotes\Data\NearMissHint;
use Commerz\Quotes\Data\QuoteState;
use Packman\Attributes\Internal;

#[Internal]
interface NearMissEvaluator
{
    /** @return list<NearMissHint> */
    public function evaluate(QuoteState $state): array;
}
