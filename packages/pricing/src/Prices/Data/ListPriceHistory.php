<?php

declare(strict_types=1);

namespace Commerz\Pricing\Prices\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Identity\SellableRef;
use Commerz\Money\Currency;
use Commerz\Pricing\PriceLists\Data\PriceListId;
use DateTimeImmutable;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class ListPriceHistory
{
    public function __construct(
        public SellableRef $sellable,
        public ?PriceListId $priceList = null,
        public ?Currency $currency = null,
        public ?DateTimeImmutable $since = null,
        public ?DateTimeImmutable $until = null,
        public int $limit = 100,
    ) {
        if ($limit < 1 || $limit > 500) {
            throw new InvalidCommand('limit', 'must be from 1 to 500', 'common.invalid_page_size');
        }
    }
}
