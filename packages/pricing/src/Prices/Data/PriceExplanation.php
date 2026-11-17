<?php

declare(strict_types=1);

namespace Commerz\Pricing\Prices\Data;

use Commerz\Ports\Prices\Data\ResolvedPrice;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class PriceExplanation
{
    /**
     * @param  ResolvedPrice|null  $price  what `resolveMany` returns for the same request; null when nothing applies and no fallback is taken
     * @param  list<PriceCandidate>  $candidates  applicable prices in ranking order (the first is selected), then the others by list code, quantity step and id
     * @param  bool  $fallbackUsed  the price is the sellable's own base price
     */
    public function __construct(public ?ResolvedPrice $price, public array $candidates, public bool $fallbackUsed) {}
}
