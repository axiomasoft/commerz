<?php

declare(strict_types=1);

namespace Commerz\Pricing\Prices\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Pricing\Common\Data\PricingCodes;
use Commerz\Pricing\PriceLists\Data\PriceListId;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class DiscardStagedPrices
{
    /** @param  list<StagedPriceId>  $stagedPriceIds */
    public function __construct(
        public ?string $batchCode = null,
        public array $stagedPriceIds = [],
        public ?PriceListId $priceList = null,
    ) {
        if ($batchCode === null && $stagedPriceIds === [] && ! $priceList instanceof PriceListId) {
            throw new InvalidCommand('stagedPrices', 'specify batchCode, stagedPriceIds, or priceList to discard', 'pricing.invalid_batch');
        }

        if ($batchCode !== null) {
            PricingCodes::code('batchCode', $batchCode);
        }
    }
}
