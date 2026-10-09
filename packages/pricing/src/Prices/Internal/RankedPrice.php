<?php

declare(strict_types=1);

namespace Commerz\Pricing\Prices\Internal;

use Commerz\Pricing\Prices\Enums\CandidateVerdict;

final readonly class RankedPrice
{
    public function __construct(public ListedPrice $price, public CandidateVerdict $verdict) {}
}
