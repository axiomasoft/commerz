<?php

declare(strict_types=1);

namespace Commerz\B2b\Quotes\Data;

use Commerz\B2b\Approvals\Data\ApprovalFlowId;
use Commerz\B2b\BusinessUnits\Data\BusinessUnitId;
use Commerz\B2b\PaymentTerms\Data\CreditHoldId;
use Commerz\B2b\PaymentTerms\Data\PaymentSchedule;
use Commerz\B2b\PaymentTerms\Data\PaymentTerms;
use Commerz\Quotes\Data\QuoteTotals;
use DateTimeImmutable;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class ConvertedQuoteOrderView
{
    /** @param list<NegotiatedQuoteLineView> $lines */
    public function __construct(
        public NegotiatedQuoteId $negotiatedQuoteId,
        public string $orderRef,
        public string $orderNumber,
        public BusinessUnitId $businessUnitId,
        public array $lines,
        public QuoteTotals $lockedTotals,
        public ?PaymentTerms $paymentTerms,
        public ?PaymentSchedule $paymentSchedule,
        public ?CreditHoldId $creditHoldId,
        public ?ApprovalFlowId $approvalFlowId,
        public DateTimeImmutable $convertedAt,
    ) {}
}
