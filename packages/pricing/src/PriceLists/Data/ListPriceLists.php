<?php

declare(strict_types=1);

namespace Commerz\Pricing\PriceLists\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Money\Currency;
use Commerz\Pagination\Cursor;
use Commerz\Pricing\PriceLists\Enums\PriceListStatus;
use Commerz\Stores\Data\ChannelId;
use Packman\Attributes\Api;

/** Price lists ordered by code, optionally of one status, assigned to one channel or in one currency. */
#[Api(since: '0.1')]
final readonly class ListPriceLists
{
    public function __construct(
        public ?PriceListStatus $status = null,
        public ?ChannelId $channel = null,
        public ?Currency $currency = null,
        public int $first = 100,
        public ?Cursor $after = null,
    ) {
        if ($first < 1 || $first > 200) {
            throw new InvalidCommand('first', 'must be from 1 to 200', 'common.invalid_page_size');
        }
    }
}
