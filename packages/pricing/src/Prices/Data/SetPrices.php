<?php

declare(strict_types=1);

namespace Commerz\Pricing\Prices\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Pricing\PriceLists\Data\PriceListId;
use Packman\Attributes\Api;

/** 1 to 1000 prices of one price list, set atomically. */
#[Api(since: '0.1')]
final readonly class SetPrices
{
    public const int MAX = 1000;

    /** @param  list<SetPrice>  $prices */
    public function __construct(public PriceListId $priceList, public array $prices)
    {
        if ($prices === [] || count($prices) > self::MAX) {
            throw new InvalidCommand('prices', 'must hold 1 to '.self::MAX.' prices', 'pricing.invalid_batch');
        }

        foreach ($prices as $price) {
            if (! $price->priceList->equals($priceList)) {
                throw new InvalidCommand('prices', 'must all belong to the price list of the batch', 'pricing.invalid_batch');
            }
        }
    }
}
