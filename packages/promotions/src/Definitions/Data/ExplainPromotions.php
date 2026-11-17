<?php

declare(strict_types=1);

namespace Commerz\Promotions\Definitions\Data;

use Commerz\Quotes\Data\QuoteInput;
use Packman\Attributes\Api;

/** Asks why each promotion did or did not apply to a quote of this input. */
#[Api(since: '0.1')]
final readonly class ExplainPromotions
{
    public function __construct(public QuoteInput $input) {}
}
