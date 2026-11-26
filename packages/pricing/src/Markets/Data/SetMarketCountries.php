<?php

declare(strict_types=1);

namespace Commerz\Pricing\Markets\Data;

use Packman\Attributes\Api;

/** Replaces the countries served by a market. */
#[Api(since: '0.1')]
final readonly class SetMarketCountries
{
    /** @var list<string> */
    public array $countries;

    /** @param  list<string>  $countries */
    public function __construct(
        public MarketId $market,
        array $countries,
    ) {
        $this->countries = CreateMarket::normalizeCountries($countries);
    }
}
