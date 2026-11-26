<?php

declare(strict_types=1);

namespace Commerz\Pricing\Prices\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Pricing\Common\Data\PricingCodes;
use Commerz\Pricing\PriceLists\Data\PriceListId;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class StagePrices
{
    public const int MAX = 1_000;

    /** @param  list<StagePrice>  $prices */
    public function __construct(
        public PriceListId $priceList,
        public array $prices,
        public ?string $batchCode = null,
    ) {
        if ($prices === [] || count($prices) > self::MAX) {
            throw new InvalidCommand('prices', 'must contain 1 to '.self::MAX.' entries', 'pricing.invalid_batch');
        }

        if ($batchCode !== null) {
            PricingCodes::code('batchCode', $batchCode);
        }

        foreach ($prices as $entry) {
            if (! $entry->priceList->equals($priceList)) {
                throw new InvalidCommand('prices', 'all staged prices must target the batch price list', 'pricing.invalid_batch');
            }
        }
    }
}
