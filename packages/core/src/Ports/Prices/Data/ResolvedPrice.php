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
     */
    public function __construct(public Money $unit, public ?Money $compareAt, public bool $taxIncluded, public string $source)
    {
        if ($unit->isNegative()) {
            throw new InvalidCommand('unit', 'must not be negative', 'prices.invalid_price');
        }

        if ($compareAt !== null && ! $compareAt->currency->equals($unit->currency)) {
            throw new InvalidCommand('compareAt', 'must be in the currency of the unit price', 'prices.invalid_price');
        }

        if ($source === '') {
            throw new InvalidCommand('source', 'must not be empty', 'prices.invalid_price');
        }
    }
}
