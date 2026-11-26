<?php

declare(strict_types=1);

namespace Commerz\Pricing\Prices\Data;

use Commerz\Money\Money;
use Commerz\Ports\Promotions\Data\AppliedPromotion;
use Commerz\Quotes\Data\QuoteResult;
use Packman\Attributes\Api;

/**
 * Result of dry-running staged prices through `QuotePipeline` against active promotions before publishing.
 */
#[Api(since: '0.1')]
final readonly class StagedPriceSimulation
{
    /**
     * @param  list<SimulatedLinePrice>  $lines
     * @param  list<AppliedPromotion>  $appliedPromotions
     */
    public function __construct(
        public ?QuoteResult $liveQuote,
        public QuoteResult $simulatedQuote,
        public array $lines,
        public array $appliedPromotions,
        public Money $totalPromotionDiscount,
        public bool $hasStackedPromotions,
    ) {}
}
