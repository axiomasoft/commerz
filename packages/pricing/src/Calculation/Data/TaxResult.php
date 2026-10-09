<?php

declare(strict_types=1);

namespace Commerz\Pricing\Calculation\Data;

use Commerz\Attributes\Api;

/** What a tax calculator answers. An empty result means nothing is taxed. */
#[Api(since: '0.1')]
final readonly class TaxResult
{
    /** @param  list<TaxAmount>  $amounts */
    public function __construct(public array $amounts) {}
}
