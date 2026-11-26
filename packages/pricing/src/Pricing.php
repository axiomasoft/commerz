<?php

declare(strict_types=1);

namespace Commerz\Pricing;

use Commerz\Pricing\Markets\Contracts\Markets;
use Commerz\Pricing\PriceLists\Contracts\PriceLists;
use Commerz\Pricing\Prices\Contracts\Prices;
use Commerz\Pricing\Prices\Contracts\StagedPrices;
use Commerz\Pricing\Taxes\Contracts\Taxes;
use Packman\Attributes\Api;

/** Root of the pricing API: accessors of the module contracts. */
#[Api(since: '0.1')]
final readonly class Pricing
{
    public function __construct(
        private PriceLists $priceLists,
        private Prices $prices,
        private Taxes $taxes,
        private Markets $markets,
        private StagedPrices $stagedPrices,
    ) {}

    public function priceLists(): PriceLists
    {
        return $this->priceLists;
    }

    public function prices(): Prices
    {
        return $this->prices;
    }

    public function stagedPrices(): StagedPrices
    {
        return $this->stagedPrices;
    }

    public function taxes(): Taxes
    {
        return $this->taxes;
    }

    public function markets(): Markets
    {
        return $this->markets;
    }
}
