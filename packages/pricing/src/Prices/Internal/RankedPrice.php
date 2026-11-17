<?php

declare(strict_types=1);

namespace Commerz\Pricing\Prices\Internal;

use Commerz\Pricing\Prices\Enums\CandidateVerdict;
use Packman\Attributes\Internal;

#[Internal]
final readonly class RankedPrice
{
    public function __construct(public ListedPrice $price, public CandidateVerdict $verdict) {}
}
