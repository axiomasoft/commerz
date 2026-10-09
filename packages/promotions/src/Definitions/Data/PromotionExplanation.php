<?php

declare(strict_types=1);

namespace Commerz\Promotions\Definitions\Data;

use Commerz\Attributes\Api;
use Commerz\Quotes\Data\QuoteResult;
use Commerz\Quotes\Data\Rejection;

/**
 * Why the promotions did what they did in a quote: the quote itself (or the failure that stopped it), a verdict for
 * every promotion that was switched on, in the order they were tried, and the rejections of presented codes. Coupon
 * promotions whose code was not presented have no verdict.
 */
#[Api(since: '0.1')]
final readonly class PromotionExplanation
{
    /**
     * @param  string|null  $failure  `quotes.failed:<stage>:<reason>` when the quote could not be computed
     * @param  list<PromotionVerdict>  $verdicts
     * @param  list<Rejection>  $codeRejections  too many, unknown and refused codes
     */
    public function __construct(
        public ?QuoteResult $quote,
        public ?string $failure,
        public array $verdicts,
        public array $codeRejections,
    ) {}
}
