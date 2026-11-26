<?php

declare(strict_types=1);

namespace Commerz\B2b\Quotes\Data;

use Commerz\B2b\BusinessUnits\Data\BusinessUnitId;
use Commerz\B2b\PaymentTerms\Data\PaymentTerms;
use Commerz\B2b\Quotes\Enums\StagedQuoteStatus;
use Commerz\Identity\ActorRef;
use Commerz\Money\Money;
use Commerz\Quotes\Data\QuoteTotals;
use DateTimeImmutable;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class StagedQuoteView
{
    public Money $total;

    /**
     * @param  list<StagedLineOverride>  $lineOverrides
     * @param  list<StagedQuoteLineView>  $lines
     */
    public function __construct(
        public StagedQuoteId $id,
        public QuoteRequestId $quoteRequestId,
        public BusinessUnitId $businessUnitId,
        public int $revision,
        public StagedQuoteStatus $status,
        public ActorRef $sellerActor,
        public array $lineOverrides,
        public array $lines,
        public QuoteTotals $totals,
        public ?PaymentTerms $paymentTerms,
        public DateTimeImmutable $validUntil,
        public ?string $sellerNote,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
        $this->total = $totals->total;
    }
}
