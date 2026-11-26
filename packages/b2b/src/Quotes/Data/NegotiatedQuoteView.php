<?php

declare(strict_types=1);

namespace Commerz\B2b\Quotes\Data;

use Commerz\B2b\Approvals\Data\ApprovalFlowId;
use Commerz\B2b\BusinessUnits\Data\BusinessUnitId;
use Commerz\B2b\PaymentTerms\Data\PaymentSchedule;
use Commerz\B2b\PaymentTerms\Data\PaymentTerms;
use Commerz\B2b\Quotes\Enums\NegotiatedQuoteStatus;
use Commerz\Context\CommerceContext;
use Commerz\Identity\CustomerRef;
use Commerz\Money\Money;
use Commerz\Ports\Delivery\Data\Address;
use Commerz\Quotes\Data\QuoteTotals;
use DateTimeImmutable;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class NegotiatedQuoteView
{
    public Money $lockedTotal;

    /**
     * @param  list<NegotiatedQuoteLineView>  $lines
     * @param  list<string>  $codes
     */
    public function __construct(
        public NegotiatedQuoteId $id,
        public QuoteRequestId $quoteRequestId,
        public StagedQuoteId $stagedQuoteId,
        public BusinessUnitId $businessUnitId,
        public CustomerRef $buyer,
        public int $revision,
        public NegotiatedQuoteStatus $status,
        public CommerceContext $context,
        public array $lines,
        public QuoteTotals $lockedTotals,
        public ?PaymentTerms $paymentTerms,
        public ?PaymentSchedule $paymentSchedule,
        public ?Address $shippingAddress,
        public ?Address $billingAddress,
        public ?string $deliveryMethod,
        public array $codes,
        public DateTimeImmutable $validUntil,
        public ?string $sellerNote,
        public ?string $renegotiationComment,
        public ?ApprovalFlowId $approvalFlowId,
        public ?string $convertedOrderRef,
        public DateTimeImmutable $sentAt,
        public ?DateTimeImmutable $convertedAt,
    ) {
        $this->lockedTotal = $lockedTotals->total;
    }

    public function isExpiredAt(DateTimeImmutable $instant): bool
    {
        return $instant > $this->validUntil;
    }
}
