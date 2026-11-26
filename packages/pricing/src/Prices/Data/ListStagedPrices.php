<?php

declare(strict_types=1);

namespace Commerz\Pricing\Prices\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Identity\SellableRef;
use Commerz\Pagination\Cursor;
use Commerz\Pricing\Common\Data\PricingCodes;
use Commerz\Pricing\PriceLists\Data\PriceListId;
use Commerz\Pricing\Prices\Enums\StagedPriceStatus;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class ListStagedPrices
{
    public function __construct(
        public ?PriceListId $priceList = null,
        public ?string $batchCode = null,
        public ?StagedPriceStatus $status = null,
        public ?SellableRef $sellable = null,
        public int $first = 100,
        public ?Cursor $after = null,
    ) {
        if ($first < 1 || $first > 200) {
            throw new InvalidCommand('first', 'must be from 1 to 200', 'common.invalid_page_size');
        }

        if ($batchCode !== null) {
            PricingCodes::code('batchCode', $batchCode);
        }
    }
}
