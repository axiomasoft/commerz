<?php

declare(strict_types=1);

namespace Commerz\Pricing\Prices\Data;

use Commerz\Attributes\Api;
use Commerz\Errors\InvalidCommand;
use Commerz\Identity\SellableRef;
use Commerz\Pagination\Cursor;
use Commerz\Pricing\PriceLists\Data\PriceListId;

/** Prices of one price list ordered by sellable, optionally of one sellable. */
#[Api(since: '0.1')]
final readonly class ListPrices
{
    public function __construct(
        public PriceListId $priceList,
        public ?SellableRef $sellable = null,
        public int $first = 100,
        public ?Cursor $after = null,
    ) {
        if ($first < 1 || $first > 200) {
            throw new InvalidCommand('first', 'must be from 1 to 200', 'common.invalid_page_size');
        }
    }
}
