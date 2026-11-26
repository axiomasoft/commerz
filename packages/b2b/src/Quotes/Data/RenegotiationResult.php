<?php

declare(strict_types=1);

namespace Commerz\B2b\Quotes\Data;

use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class RenegotiationResult
{
    public function __construct(
        public NegotiatedQuoteView $negotiatedQuote,
        public StagedQuoteView $stagedQuote,
        public QuoteRequestView $quoteRequest,
    ) {}
}
