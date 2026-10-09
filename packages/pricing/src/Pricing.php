<?php

declare(strict_types=1);

namespace Commerz\Pricing;

use Commerz\Attributes\Api;
use Commerz\Pricing\PriceLists\Contracts\PriceLists;
use Commerz\Pricing\Prices\Contracts\Prices;
use Commerz\Pricing\Taxes\Contracts\Taxes;

/** Root of the pricing API: accessors of the module contracts. */
#[Api(since: '0.1')]
final readonly class Pricing
{
    public function __construct(
        private PriceLists $priceLists,
        private Prices $prices,
        private Taxes $taxes,
    ) {}

    public function priceLists(): PriceLists
    {
        return $this->priceLists;
    }

    public function prices(): Prices
    {
        return $this->prices;
    }

    public function taxes(): Taxes
    {
        return $this->taxes;
    }
}
