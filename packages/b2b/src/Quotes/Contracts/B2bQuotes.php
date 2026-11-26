<?php

declare(strict_types=1);

namespace Commerz\B2b\Quotes\Contracts;

use Commerz\B2b\Approvals\Exceptions\ApprovalFlowNotFound;
use Commerz\B2b\Approvals\Exceptions\ApprovalNotGranted;
use Commerz\B2b\Approvals\Exceptions\ApprovalRequired;
use Commerz\B2b\BusinessUnits\Exceptions\AssociateNotFound;
use Commerz\B2b\BusinessUnits\Exceptions\AssociateUnauthorized;
use Commerz\B2b\BusinessUnits\Exceptions\BusinessUnitInactive;
use Commerz\B2b\BusinessUnits\Exceptions\BusinessUnitNotFound;
use Commerz\B2b\PaymentTerms\Exceptions\CreditAccountFrozen;
use Commerz\B2b\PaymentTerms\Exceptions\CreditAccountNotFound;
use Commerz\B2b\PaymentTerms\Exceptions\CreditLimitExceeded;
use Commerz\B2b\PaymentTerms\Exceptions\RecurringSellingPlanIncompatibleWithPaymentTerms;
use Commerz\B2b\Quotes\Data\ConvertedQuoteOrderView;
use Commerz\B2b\Quotes\Data\ConvertQuoteToOrder;
use Commerz\B2b\Quotes\Data\NegotiatedQuoteId;
use Commerz\B2b\Quotes\Data\NegotiatedQuoteView;
use Commerz\B2b\Quotes\Data\PublishNegotiatedQuote;
use Commerz\B2b\Quotes\Data\QuoteRequestId;
use Commerz\B2b\Quotes\Data\QuoteRequestView;
use Commerz\B2b\Quotes\Data\RenegotiationResult;
use Commerz\B2b\Quotes\Data\RequestRenegotiation;
use Commerz\B2b\Quotes\Data\StagedQuoteId;
use Commerz\B2b\Quotes\Data\StagedQuoteView;
use Commerz\B2b\Quotes\Data\StageQuote;
use Commerz\B2b\Quotes\Data\SubmitQuoteRequest;
use Commerz\B2b\Quotes\Data\UpdateStagedQuote;
use Commerz\B2b\Quotes\Exceptions\InvalidQuoteState;
use Commerz\B2b\Quotes\Exceptions\InvalidQuoteValidity;
use Commerz\B2b\Quotes\Exceptions\QuoteAlreadyConverted;
use Commerz\B2b\Quotes\Exceptions\QuoteExpired;
use Commerz\B2b\Quotes\Exceptions\QuoteNotFound;
use Commerz\Errors\IdempotencyConflict;
use Commerz\Ports\Sellables\Exceptions\SellableUnavailable;
use Commerz\Quotes\Exceptions\QuoteFailed;
use Packman\Attributes\Api;

/**
 * 3-stage B2B Quotes workflow (`QuoteRequest -> StagedQuote -> NegotiatedQuote`) with `validUntil`,
 * `requestRenegotiation`, approval & credit checks, and locked-price conversion to an `Order`.
 */
#[Api(since: '0.1')]
interface B2bQuotes
{
    /**
     * Stage 1: Buyer submits a `QuoteRequest` (from a cart or explicit lines).
     *
     * @throws BusinessUnitNotFound|BusinessUnitInactive
     * @throws AssociateNotFound|AssociateUnauthorized
     * @throws RecurringSellingPlanIncompatibleWithPaymentTerms
     */
    public function submitRequest(SubmitQuoteRequest $command): QuoteRequestView;

    /**
     * Stage 2: Seller opens a `StagedQuote` workspace for a `QuoteRequest`, applying custom line unit prices,
     * payment terms, and `validUntil`.
     *
     * @throws QuoteNotFound|InvalidQuoteState|InvalidQuoteValidity
     * @throws RecurringSellingPlanIncompatibleWithPaymentTerms
     * @throws SellableUnavailable|QuoteFailed
     */
    public function stage(StageQuote $command): StagedQuoteView;

    /**
     * Updates a draft `StagedQuote` (e.g. during initial staging or renegotiation).
     *
     * @throws QuoteNotFound|InvalidQuoteState|InvalidQuoteValidity
     * @throws RecurringSellingPlanIncompatibleWithPaymentTerms
     * @throws SellableUnavailable|QuoteFailed
     */
    public function updateStaged(UpdateStagedQuote $command): StagedQuoteView;

    /**
     * Stage 3: Publishes a `StagedQuote` into a locked `NegotiatedQuote` offer sent to the buyer.
     *
     * @throws QuoteNotFound|InvalidQuoteState|InvalidQuoteValidity
     */
    public function publish(PublishNegotiatedQuote $command): NegotiatedQuoteView;

    /**
     * Buyer requests renegotiation on a `Sent` `NegotiatedQuote`, transitioning it to `RenegotiationRequested` and
     * reopening a new draft `StagedQuote` revision for the seller.
     *
     * @throws QuoteNotFound|InvalidQuoteState|QuoteExpired
     * @throws AssociateNotFound|AssociateUnauthorized
     */
    public function requestRenegotiation(RequestRenegotiation $command): RenegotiationResult;

    /**
     * Converts a `NegotiatedQuote` into an `Order` at its locked unit prices, enforcing `validUntil`, `ApprovalFlow`
     * budget thresholds & spending limits, `PaymentTerms` non-recurring plan rules, and atomic credit reservation.
     *
     * @throws QuoteNotFound|InvalidQuoteState|QuoteExpired|QuoteAlreadyConverted
     * @throws AssociateNotFound|AssociateUnauthorized
     * @throws ApprovalRequired|ApprovalFlowNotFound|ApprovalNotGranted
     * @throws RecurringSellingPlanIncompatibleWithPaymentTerms
     * @throws CreditAccountNotFound|CreditAccountFrozen|CreditLimitExceeded
     * @throws IdempotencyConflict
     */
    public function convertToOrder(ConvertQuoteToOrder $command): ConvertedQuoteOrderView;

    public function findRequest(QuoteRequestId $id): ?QuoteRequestView;

    /** @throws QuoteNotFound */
    public function getRequest(QuoteRequestId $id): QuoteRequestView;

    public function findStaged(StagedQuoteId $id): ?StagedQuoteView;

    /** @throws QuoteNotFound */
    public function getStaged(StagedQuoteId $id): StagedQuoteView;

    public function findNegotiated(NegotiatedQuoteId $id): ?NegotiatedQuoteView;

    /** @throws QuoteNotFound */
    public function getNegotiated(NegotiatedQuoteId $id): NegotiatedQuoteView;
}
