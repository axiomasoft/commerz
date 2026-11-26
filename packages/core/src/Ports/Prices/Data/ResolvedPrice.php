<?php

declare(strict_types=1);

namespace Commerz\Ports\Prices\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Money\Money;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class ResolvedPrice
{
    /**
     * @param  Money  $unit  price of one sellable unit
     * @param  bool  $taxIncluded  whether `$unit` already contains tax
     * @param  string  $source  why this price won, e.g. `price_list:retail` or `sellable`
     * @param  Money|null  $lowestPriceLast30Days  EU Omnibus rolling 30-day minimum when `$compareAt` is present (INV-PRC-002)
     */
    public function __construct(
        public Money $unit,
        public ?Money $compareAt,
        public bool $taxIncluded,
        public string $source,
        public ?Money $lowestPriceLast30Days = null,
    ) {
        if ($unit->isNegative()) {
            throw new InvalidCommand('unit', 'must not be negative', 'prices.invalid_price');
        }

        if ($compareAt !== null && ! $compareAt->currency->equals($unit->currency)) {
            throw new InvalidCommand('compareAt', 'must be in the currency of the unit price', 'prices.invalid_price');
        }

        if ($lowestPriceLast30Days !== null) {
            if ($lowestPriceLast30Days->isNegative()) {
                throw new InvalidCommand('lowestPriceLast30Days', 'must not be negative', 'prices.invalid_price');
            }

            if (! $lowestPriceLast30Days->currency->equals($unit->currency)) {
                throw new InvalidCommand('lowestPriceLast30Days', 'must be in the currency of the unit price', 'prices.invalid_price');
            }
        }

        if ($source === '') {
            throw new InvalidCommand('source', 'must not be empty', 'prices.invalid_price');
        }
    }
}
