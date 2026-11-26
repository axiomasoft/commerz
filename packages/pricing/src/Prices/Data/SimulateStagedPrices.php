<?php

declare(strict_types=1);

namespace Commerz\Pricing\Prices\Data;

use Commerz\Context\CommerceContext;
use Commerz\Ports\Sellables\Data\SellableSnapshot;
use Commerz\Pricing\Common\Data\PricingCodes;
use Commerz\Pricing\PriceLists\Data\PriceListId;
use Commerz\Quotes\Data\QuoteInput;
use Commerz\Quotes\Data\QuoteLineInput;
use Packman\Attributes\Api;

/**
 * Dry-run simulation request evaluating staged prices through `QuotePipeline` against active promotions before publishing.
 */
#[Api(since: '0.1')]
final readonly class SimulateStagedPrices
{
    /**
     * @param  list<StagedPriceId>  $stagedPriceIds
     */
    public function __construct(
        public QuoteInput $quoteInput,
        public ?string $batchCode = null,
        public array $stagedPriceIds = [],
        public ?PriceListId $priceList = null,
    ) {
        if ($batchCode !== null) {
            PricingCodes::code('batchCode', $batchCode);
        }
    }

    /**
     * Convenience constructor building a `QuoteInput` with 1-unit (or custom quantity) lines for the given sellables.
     *
     * @param  list<SellableSnapshot>  $sellables
     * @param  list<string>  $codes  optional coupon codes to test alongside automatic promotions
     * @param  list<StagedPriceId>  $stagedPriceIds
     */
    public static function forSellables(
        CommerceContext $context,
        array $sellables,
        ?string $batchCode = null,
        array $stagedPriceIds = [],
        ?PriceListId $priceList = null,
        array $codes = [],
        int $quantity = 1,
    ): self {
        $lines = [];

        foreach ($sellables as $index => $sellable) {
            $lines[] = new QuoteLineInput('sim-'.($index + 1), $sellable, $quantity);
        }

        return new self(
            quoteInput: new QuoteInput($context, $lines, $codes),
            batchCode: $batchCode,
            stagedPriceIds: $stagedPriceIds,
            priceList: $priceList,
        );
    }
}
