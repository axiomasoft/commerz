<?php

declare(strict_types=1);

namespace Commerz\B2b\Quotes\Events;

use Commerz\B2b\Quotes\Data\NegotiatedQuoteId;
use Commerz\B2b\Quotes\Data\QuoteRequestId;
use Commerz\B2b\Quotes\Data\StagedQuoteId;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class QuoteRenegotiationRequested
{
    public function __construct(
        public NegotiatedQuoteId $negotiatedQuoteId,
        public StagedQuoteId $reopenedStagedQuoteId,
        public QuoteRequestId $quoteRequestId,
        public int $nextRevision,
    ) {}
}
